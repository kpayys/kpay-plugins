// Package kpay 是 KPay 的 Go SDK（无第三方依赖）。
//
//	client := kpay.New("1001", "EPay Key")
//	payURL, _ := client.PayURL(kpay.Order{OutTradeNo: "A1", Name: "会员", Money: "9.90",
//		NotifyURL: "https://你的域名/notify", ReturnURL: "https://你的域名/done"})
//	// 异步通知：client.VerifyNotify(r.URL.Query()) 为 true 且 trade_status == "TRADE_SUCCESS" 时入账，返回纯文本 success
package kpay

import (
	"bytes"
	"context"
	"crypto/hmac"
	"crypto/md5"
	"crypto/rand"
	"crypto/sha256"
	"crypto/subtle"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"math"
	"net/http"
	"net/url"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"time"
)

// DefaultGateway 是 KPay 的接口地址。
const DefaultGateway = "https://api.kaipay.cn/"

// Client 调用 KPay 的 EPay 兼容接口和平台 API。
type Client struct {
	PID        string // 商户ID（KPay「EPay 接入 → EPay 配置」）
	Key        string // EPay Key（「API 密钥」页创建 EPay 兼容密钥时显示）
	Gateway    string
	APIKey     string // 选填，平台 API 密钥（退款用）
	APISecret  string
	HTTPClient *http.Client
}

// Order 是下单参数。Type 可选 alipay / wxpay / unionpay，留空由付款人在 KPay 收银台选。
type Order struct {
	OutTradeNo string
	Name       string
	Money      string
	NotifyURL  string
	ReturnURL  string
	Type       string
	Device     string // pc / mobile
}

// Error 带有 KPay 的原始响应。
type Error struct {
	Message  string
	Response map[string]any
}

func (e *Error) Error() string { return e.Message }

// New 创建客户端。
func New(pid, key string) *Client {
	return &Client{PID: strings.TrimSpace(pid), Key: strings.TrimSpace(key), Gateway: DefaultGateway}
}

// Sign 是 EPay V1 签名：去掉 sign、sign_type 和空值，按参数名升序拼成 a=1&b=2，直接接上密钥，取 MD5 小写。
func Sign(params map[string]string, key string) string {
	names := make([]string, 0, len(params))
	for name, value := range params {
		if name == "sign" || name == "sign_type" || strings.TrimSpace(value) == "" {
			continue
		}
		names = append(names, name)
	}
	sort.Strings(names)
	var b strings.Builder
	for i, name := range names {
		if i > 0 {
			b.WriteByte('&')
		}
		b.WriteString(name)
		b.WriteByte('=')
		b.WriteString(params[name])
	}
	b.WriteString(key)
	sum := md5.Sum([]byte(b.String()))
	return hex.EncodeToString(sum[:])
}

var gatewayPattern = regexp.MustCompile(`(?i)^https?://[^/\s]+`)
var epaySuffix = regexp.MustCompile(`(?i)/epay$`)

// NormalizeGateway 统一接口地址为以 / 结尾的根地址。
func NormalizeGateway(raw string) string {
	raw = strings.TrimSpace(raw)
	if raw == "" || !gatewayPattern.MatchString(raw) {
		return DefaultGateway
	}
	return epaySuffix.ReplaceAllString(strings.TrimRight(raw, "/"), "") + "/"
}

// SignOrder 生成带签名的下单参数。
func (c *Client) SignOrder(order Order) (url.Values, error) {
	if c.PID == "" || c.Key == "" {
		return nil, &Error{Message: "商户ID或 EPay Key 未配置"}
	}
	money, err := strconv.ParseFloat(strings.TrimSpace(order.Money), 64)
	if err != nil || money <= 0 {
		return nil, &Error{Message: "金额不正确"}
	}
	if order.OutTradeNo == "" || order.Name == "" || order.NotifyURL == "" {
		return nil, &Error{Message: "缺少 out_trade_no、name 或 notify_url"}
	}
	params := map[string]string{
		"pid":          c.PID,
		"out_trade_no": order.OutTradeNo,
		"notify_url":   order.NotifyURL,
		"return_url":   order.ReturnURL,
		"name":         order.Name,
		"money":        strconv.FormatFloat(math.Round(money*100)/100, 'f', 2, 64),
		"type":         order.Type,
		"device":       order.Device,
	}
	values := url.Values{}
	for k, v := range params {
		if strings.TrimSpace(v) != "" {
			values.Set(k, v)
		}
	}
	values.Set("sign", Sign(params, c.Key))
	values.Set("sign_type", "MD5")
	return values, nil
}

// PayURL 返回浏览器跳转下单的地址（GET /epay/submit）。
func (c *Client) PayURL(order Order) (string, error) {
	values, err := c.SignOrder(order)
	if err != nil {
		return "", err
	}
	return c.gateway() + "epay/submit?" + values.Encode(), nil
}

// CreateOrder 服务端下单（POST /epay/mapi），返回里的 payurl 是付款页地址。
func (c *Client) CreateOrder(ctx context.Context, order Order) (map[string]any, error) {
	values, err := c.SignOrder(order)
	if err != nil {
		return nil, err
	}
	result, err := c.do(ctx, http.MethodPost, c.gateway()+"epay/mapi", []byte(values.Encode()), map[string]string{"Content-Type": "application/x-www-form-urlencoded"})
	if err != nil {
		return nil, err
	}
	if code(result) != 1 {
		return nil, &Error{Message: message(result, "下单失败"), Response: result}
	}
	return result, nil
}

// QueryOrder 查询订单，tradeNo 为 KPay 订单号，留空时用 outTradeNo。
func (c *Client) QueryOrder(ctx context.Context, tradeNo, outTradeNo string) (map[string]any, error) {
	query := url.Values{"act": {"order"}, "pid": {c.PID}, "key": {c.Key}}
	if tradeNo != "" {
		query.Set("trade_no", tradeNo)
	} else {
		query.Set("out_trade_no", outTradeNo)
	}
	result, err := c.do(ctx, http.MethodGet, c.gateway()+"epay/api?"+query.Encode(), nil, nil)
	if err != nil {
		return nil, err
	}
	if code(result) != 1 {
		return nil, &Error{Message: message(result, "查询失败"), Response: result}
	}
	return result, nil
}

// VerifyNotify 校验异步通知 / 同步跳转带回的参数（签名 + 商户ID）。入账前还要核对 money 和订单金额。
func (c *Client) VerifyNotify(values url.Values) bool {
	params := make(map[string]string, len(values))
	for k := range values {
		params[k] = values.Get(k)
	}
	if c.PID == "" || c.Key == "" || params["sign"] == "" || params["pid"] != c.PID {
		return false
	}
	expected := Sign(params, c.Key)
	actual := strings.ToLower(strings.TrimSpace(params["sign"]))
	return subtle.ConstantTimeCompare([]byte(expected), []byte(actual)) == 1
}

// Refund 退款（需要勾选「发起退款」权限的平台 API 密钥）。同一笔退款重试时 refundRequestNo 保持不变。
func (c *Client) Refund(ctx context.Context, tradeNo string, amount float64, refundRequestNo, reason string) (map[string]any, error) {
	if c.APIKey == "" || c.APISecret == "" {
		return nil, &Error{Message: "未配置平台 API 密钥"}
	}
	path := "/pay/api/order/refund"
	body, err := RefundBody(tradeNo, amount, refundRequestNo, reason)
	if err != nil {
		return nil, err
	}
	headers := HMACHeaders(c.APIKey, c.APISecret, http.MethodPost, path, body, "", "")
	headers["Content-Type"] = "application/json"
	result, err := c.do(ctx, http.MethodPost, strings.TrimRight(c.gateway(), "/")+path, body, headers)
	if err != nil {
		return nil, err
	}
	if code(result) != 0 {
		return nil, &Error{Message: message(result, "退款失败"), Response: result}
	}
	return result, nil
}

// RefundBody 生成退款请求体（字段顺序固定，便于排查签名）。
func RefundBody(tradeNo string, amount float64, refundRequestNo, reason string) ([]byte, error) {
	var buf bytes.Buffer
	enc := json.NewEncoder(&buf)
	enc.SetEscapeHTML(false)
	err := enc.Encode(struct {
		OrderNo         string  `json:"orderNo"`
		RefundAmount    float64 `json:"refundAmount"`
		RefundRequestNo string  `json:"refundRequestNo"`
		Reason          string  `json:"reason"`
	}{tradeNo, math.Round(amount*100) / 100, refundRequestNo, reason})
	return bytes.TrimRight(buf.Bytes(), "\n"), err
}

// HMACHeaders 生成平台 API 的签名请求头。签名原文：METHOD \n PATH?QUERY \n 时间戳 \n nonce \n SHA256(请求体)。
// timestamp、nonce 传空时自动生成。
func HMACHeaders(apiKey, apiSecret, method, requestURI string, body []byte, timestamp, nonce string) map[string]string {
	if timestamp == "" {
		timestamp = strconv.FormatInt(time.Now().Unix(), 10)
	}
	if nonce == "" {
		buf := make([]byte, 16)
		_, _ = rand.Read(buf)
		nonce = hex.EncodeToString(buf)
	}
	bodySum := sha256.Sum256(body)
	bodyHash := hex.EncodeToString(bodySum[:])
	canonical := strings.Join([]string{strings.ToUpper(method), requestURI, timestamp, nonce, bodyHash}, "\n")
	mac := hmac.New(sha256.New, []byte(apiSecret))
	mac.Write([]byte(canonical))
	return map[string]string{
		"X-API-Key":               apiKey,
		"X-KPay-Timestamp":        timestamp,
		"X-KPay-Nonce":            nonce,
		"X-KPay-Body-SHA256":      bodyHash,
		"X-KPay-Signature-Method": "HMAC-SHA256",
		"X-KPay-Signature":        hex.EncodeToString(mac.Sum(nil)),
	}
}

func (c *Client) gateway() string { return NormalizeGateway(c.Gateway) }

func (c *Client) do(ctx context.Context, method, target string, body []byte, headers map[string]string) (map[string]any, error) {
	var reader io.Reader
	if body != nil {
		reader = bytes.NewReader(body)
	}
	req, err := http.NewRequestWithContext(ctx, method, target, reader)
	if err != nil {
		return nil, err
	}
	req.Header.Set("Accept", "application/json")
	for k, v := range headers {
		req.Header.Set(k, v)
	}
	httpClient := c.HTTPClient
	if httpClient == nil {
		httpClient = &http.Client{Timeout: 15 * time.Second}
	}
	resp, err := httpClient.Do(req)
	if err != nil {
		return nil, &Error{Message: fmt.Sprintf("连接 KPay 失败：%v", err)}
	}
	defer resp.Body.Close()
	raw, err := io.ReadAll(io.LimitReader(resp.Body, 1<<20))
	if err != nil {
		return nil, err
	}
	var result map[string]any
	if err := json.Unmarshal(raw, &result); err != nil {
		return nil, &Error{Message: "KPay 返回数据无法解析"}
	}
	return result, nil
}

func code(result map[string]any) int {
	switch v := result["code"].(type) {
	case float64:
		return int(v)
	case string:
		n, _ := strconv.Atoi(v)
		return n
	}
	return math.MinInt32
}

func message(result map[string]any, fallback string) string {
	if msg, ok := result["msg"].(string); ok && msg != "" {
		return msg
	}
	return fallback
}
