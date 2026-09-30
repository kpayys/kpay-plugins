package kpay

import (
	"context"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"strings"
	"testing"
)

type vectorFile struct {
	Key   string `json:"key"`
	Cases []struct {
		Params map[string]string `json:"params"`
		Sign   string            `json:"sign"`
	} `json:"cases"`
	HMAC struct {
		Secret, RequestURI, Timestamp, Nonce, Body, Signature string
	} `json:"hmac"`
	HMACGet struct {
		RequestURI, Signature string
	} `json:"hmacGet"`
}

func loadVectors(t *testing.T) vectorFile {
	t.Helper()
	raw, err := os.ReadFile("../../tests/vectors.json")
	if err != nil {
		t.Fatal(err)
	}
	var v vectorFile
	if err := json.Unmarshal(raw, &v); err != nil {
		t.Fatal(err)
	}
	return v
}

func TestSignVectors(t *testing.T) {
	v := loadVectors(t)
	for i, c := range v.Cases {
		if got := Sign(c.Params, v.Key); got != c.Sign {
			t.Errorf("case %d: got %s want %s", i, got, c.Sign)
		}
	}
}

func TestVerifyNotify(t *testing.T) {
	v := loadVectors(t)
	values := url.Values{}
	for k, val := range v.Cases[2].Params {
		values.Set(k, val)
	}
	values.Set("sign", v.Cases[2].Sign)
	values.Set("sign_type", "MD5")
	client := New("1001", v.Key)
	if !client.VerifyNotify(values) {
		t.Fatal("valid notify rejected")
	}
	tampered := url.Values{}
	for k := range values {
		tampered.Set(k, values.Get(k))
	}
	tampered.Set("money", "1.00")
	if client.VerifyNotify(tampered) {
		t.Fatal("tampered notify accepted")
	}
	if New("1002", v.Key).VerifyNotify(values) {
		t.Fatal("other merchant accepted")
	}
}

func TestPayURL(t *testing.T) {
	v := loadVectors(t)
	client := New("1001", v.Key)
	client.Gateway = "https://api.kaipay.cn/epay/"
	raw, err := client.PayURL(Order{OutTradeNo: "A1", Name: "会员 & 充值", Money: "9.9", NotifyURL: "https://shop.example.com/n", Type: "alipay"})
	if err != nil {
		t.Fatal(err)
	}
	if !strings.HasPrefix(raw, "https://api.kaipay.cn/epay/submit?") {
		t.Fatalf("unexpected url %s", raw)
	}
	u, _ := url.Parse(raw)
	params := map[string]string{}
	for k := range u.Query() {
		params[k] = u.Query().Get(k)
	}
	if params["money"] != "9.90" || Sign(params, v.Key) != params["sign"] {
		t.Fatalf("bad params %v", params)
	}
	if _, err := client.PayURL(Order{OutTradeNo: "A1"}); err == nil {
		t.Fatal("missing fields accepted")
	}
}

func TestHMACVectors(t *testing.T) {
	v := loadVectors(t)
	h := HMACHeaders("ak", v.HMAC.Secret, "POST", v.HMAC.RequestURI, []byte(v.HMAC.Body), v.HMAC.Timestamp, v.HMAC.Nonce)
	if h["X-KPay-Signature"] != v.HMAC.Signature {
		t.Fatalf("POST signature %s want %s", h["X-KPay-Signature"], v.HMAC.Signature)
	}
	g := HMACHeaders("ak", v.HMAC.Secret, "GET", v.HMACGet.RequestURI, nil, v.HMAC.Timestamp, v.HMAC.Nonce)
	if g["X-KPay-Signature"] != v.HMACGet.Signature {
		t.Fatalf("GET signature %s want %s", g["X-KPay-Signature"], v.HMACGet.Signature)
	}
	body, _ := RefundBody("P20260930120000001", 1.5, "R2026093012345", "易支付后台退款")
	if string(body) != v.HMAC.Body {
		t.Fatalf("refund body %s want %s", body, v.HMAC.Body)
	}
}

func TestCreateOrderAndRefundAgainstServer(t *testing.T) {
	v := loadVectors(t)
	var lastBody string
	var lastPath string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		raw, _ := io.ReadAll(r.Body)
		lastBody, lastPath = string(raw), r.URL.Path
		switch r.URL.Path {
		case "/epay/mapi":
			_, _ = w.Write([]byte(`{"code":1,"payurl":"https://api.kaipay.cn/checkout/x"}`))
		case "/pay/api/order/refund":
			_, _ = w.Write([]byte(`{"code":7,"msg":"无退款权限"}`))
		}
	}))
	defer server.Close()

	client := New("1001", v.Key)
	client.Gateway = server.URL
	result, err := client.CreateOrder(context.Background(), Order{OutTradeNo: "A1", Name: "n", Money: "1", NotifyURL: "https://x.example.com/n"})
	if err != nil || result["payurl"] != "https://api.kaipay.cn/checkout/x" || lastPath != "/epay/mapi" {
		t.Fatalf("create order: %v %v %s", err, result, lastPath)
	}
	form, _ := url.ParseQuery(lastBody)
	if form.Get("money") != "1.00" {
		t.Fatalf("money %s", form.Get("money"))
	}

	client.APIKey, client.APISecret = "ak", "sk"
	_, err = client.Refund(context.Background(), "P1", 1, "R1", "")
	if err == nil || err.Error() != "无退款权限" {
		t.Fatalf("refund error %v", err)
	}
}
