"""KPay Python SDK（单文件，只用标准库，Python 3.8+）

    from kpay import KPay
    kpay = KPay("1001", "EPay Key")
    url = kpay.pay_url({"out_trade_no": "A1", "name": "会员", "money": "9.90",
                        "notify_url": "https://你的域名/notify", "return_url": "https://你的域名/done"})
    # 异步通知：kpay.verify_notify(request_args) 为 True 且 trade_status == "TRADE_SUCCESS" 时入账，返回纯文本 success
"""

from __future__ import annotations

import hashlib
import hmac
import json
import re
import secrets
import time
import urllib.parse
import urllib.request
from typing import Any, Dict, Mapping, Optional

DEFAULT_GATEWAY = "https://api.kaipay.cn/"
_ORDER_FIELDS = ("type", "out_trade_no", "notify_url", "return_url", "name", "money", "device")


class KPayError(Exception):
    def __init__(self, message: str, response: Optional[Dict[str, Any]] = None):
        super().__init__(message)
        self.response = response or {}


def sign(params: Mapping[str, Any], key: str) -> str:
    """EPay V1 签名：去掉 sign、sign_type 和空值，按参数名升序拼成 a=1&b=2，直接接上密钥，取 MD5 小写"""
    pairs = []
    for name in sorted(params, key=lambda k: str(k).encode("utf-8")):
        if name in ("sign", "sign_type"):
            continue
        value = params[name]
        if value is None or isinstance(value, (dict, list, tuple)):
            continue
        value = str(value)
        if value.strip() == "":
            continue
        pairs.append(f"{name}={value}")
    return hashlib.md5(("&".join(pairs) + key).encode("utf-8")).hexdigest()


def normalize_gateway(url: str) -> str:
    url = (url or "").strip()
    if not url or not re.match(r"^https?://[^/\s]+", url, re.I):
        return DEFAULT_GATEWAY
    return re.sub(r"/epay$", "", url.rstrip("/"), flags=re.I) + "/"


def hmac_headers(api_key: str, api_secret: str, method: str, request_uri: str, body: str,
                 timestamp: Optional[str] = None, nonce: Optional[str] = None) -> Dict[str, str]:
    """平台 API 的 HMAC 请求头。签名原文：METHOD \\n PATH?QUERY \\n 时间戳 \\n nonce \\n SHA256(请求体)"""
    timestamp = timestamp or str(int(time.time()))
    nonce = nonce or secrets.token_hex(16)
    body_hash = hashlib.sha256(body.encode("utf-8")).hexdigest()
    canonical = "\n".join([method.upper(), request_uri, timestamp, nonce, body_hash])
    return {
        "X-API-Key": api_key,
        "X-KPay-Timestamp": timestamp,
        "X-KPay-Nonce": nonce,
        "X-KPay-Body-SHA256": body_hash,
        "X-KPay-Signature-Method": "HMAC-SHA256",
        "X-KPay-Signature": hmac.new(api_secret.encode("utf-8"), canonical.encode("utf-8"), hashlib.sha256).hexdigest(),
    }


class KPay:
    def __init__(self, pid: str, key: str, gateway: str = "", api_key: str = "", api_secret: str = "", timeout: float = 15):
        self.pid = str(pid).strip()
        self.key = str(key).strip()
        self.gateway = normalize_gateway(gateway)
        self.api_key = api_key.strip()
        self.api_secret = api_secret.strip()
        self.timeout = timeout

    def sign_order(self, order: Mapping[str, Any]) -> Dict[str, str]:
        if not self.pid or not self.key:
            raise KPayError("商户ID或 EPay Key 未配置")
        for field in ("out_trade_no", "name", "money", "notify_url"):
            if str(order.get(field, "")).strip() == "":
                raise KPayError(f"缺少参数 {field}")
        params = {"pid": self.pid}
        for field in _ORDER_FIELDS:
            value = order.get(field)
            if value is not None and str(value).strip() != "":
                params[field] = str(value)
        params["money"] = f"{float(params['money']):.2f}"
        params["sign"] = sign(params, self.key)
        params["sign_type"] = "MD5"
        return params

    def pay_url(self, order: Mapping[str, Any]) -> str:
        """浏览器跳转下单的地址（GET /epay/submit）"""
        return self.gateway + "epay/submit?" + urllib.parse.urlencode(self.sign_order(order))

    def create_order(self, order: Mapping[str, Any]) -> Dict[str, Any]:
        """服务端下单（POST /epay/mapi），返回里的 payurl 是付款页地址"""
        body = urllib.parse.urlencode(self.sign_order(order))
        result = self._request("POST", self.gateway + "epay/mapi", body, {"Content-Type": "application/x-www-form-urlencoded"})
        if int(result.get("code", 0)) != 1:
            raise KPayError(result.get("msg") or "下单失败", result)
        return result

    def query_order(self, trade_no: str = "", out_trade_no: str = "") -> Dict[str, Any]:
        query = {"act": "order", "pid": self.pid, "key": self.key}
        if trade_no:
            query["trade_no"] = trade_no
        else:
            query["out_trade_no"] = out_trade_no
        result = self._request("GET", self.gateway + "epay/api?" + urllib.parse.urlencode(query), None, {})
        if int(result.get("code", 0)) != 1:
            raise KPayError(result.get("msg") or "查询失败", result)
        return result

    def verify_notify(self, data: Mapping[str, Any]) -> bool:
        """校验异步通知 / 同步跳转带回的参数（签名 + 商户ID）。入账前还要核对 money 和订单金额"""
        signature = data.get("sign")
        if not self.pid or not self.key or not isinstance(signature, str):
            return False
        if str(data.get("pid", "")) != self.pid:
            return False
        return hmac.compare_digest(sign(data, self.key), signature.strip().lower())

    def refund(self, trade_no: str, amount: float, refund_request_no: str, reason: str = "") -> Dict[str, Any]:
        """退款（需要勾选「发起退款」权限的平台 API 密钥）。同一笔退款重试时 refund_request_no 保持不变"""
        if not self.api_key or not self.api_secret:
            raise KPayError("未配置平台 API 密钥")
        path = "/pay/api/order/refund"
        body = json.dumps({"orderNo": trade_no, "refundAmount": round(float(amount), 2),
                           "refundRequestNo": refund_request_no, "reason": reason},
                          ensure_ascii=False, separators=(",", ":"))
        headers = {"Content-Type": "application/json", **hmac_headers(self.api_key, self.api_secret, "POST", path, body)}
        result = self._request("POST", self.gateway.rstrip("/") + path, body, headers)
        if int(result.get("code", -1)) != 0:
            raise KPayError(result.get("msg") or "退款失败", result)
        return result

    def _request(self, method: str, url: str, body: Optional[str], headers: Dict[str, str]) -> Dict[str, Any]:
        data = body.encode("utf-8") if body is not None else None
        req = urllib.request.Request(url, data=data, method=method, headers={"Accept": "application/json", **headers})
        try:
            with urllib.request.urlopen(req, timeout=self.timeout) as resp:
                raw = resp.read().decode("utf-8")
        except OSError as exc:
            raise KPayError(f"连接 KPay 失败：{exc}") from exc
        try:
            result = json.loads(raw)
        except ValueError as exc:
            raise KPayError("KPay 返回数据无法解析") from exc
        if not isinstance(result, dict):
            raise KPayError("KPay 返回数据无法解析")
        return result
