import json
import pathlib
import unittest
import urllib.parse

import kpay

VECTORS = json.loads((pathlib.Path(__file__).resolve().parents[2] / "tests" / "vectors.json").read_text(encoding="utf-8"))
KEY = VECTORS["key"]


class FakeKPay(kpay.KPay):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.requests = []
        self.responses = []

    def _request(self, method, url, body, headers):
        self.requests.append((method, url, body, headers))
        return self.responses.pop(0)


class KPayTest(unittest.TestCase):
    def test_sign_vectors(self):
        for case in VECTORS["cases"]:
            self.assertEqual(kpay.sign(case["params"], KEY), case["sign"])

    def test_verify_notify(self):
        data = dict(VECTORS["cases"][2]["params"], sign=VECTORS["cases"][2]["sign"], sign_type="MD5")
        client = kpay.KPay("1001", KEY)
        self.assertTrue(client.verify_notify(data))
        self.assertTrue(client.verify_notify(dict(data, sign=data["sign"].upper())))
        self.assertFalse(client.verify_notify(dict(data, money="1.00")))
        self.assertFalse(kpay.KPay("1002", KEY).verify_notify(data))

    def test_pay_url(self):
        client = kpay.KPay("1001", KEY, "https://api.kaipay.cn/epay/")
        url = client.pay_url({"out_trade_no": "A1", "name": "会员 & 充值", "money": "9.9",
                              "notify_url": "https://shop.example.com/notify", "type": "alipay"})
        self.assertTrue(url.startswith("https://api.kaipay.cn/epay/submit?"))
        params = dict(urllib.parse.parse_qsl(url.split("?", 1)[1]))
        self.assertEqual(params["money"], "9.90")
        self.assertEqual(kpay.sign(params, KEY), params["sign"])
        with self.assertRaises(kpay.KPayError):
            client.pay_url({"out_trade_no": "A1"})

    def test_create_order_and_errors(self):
        client = FakeKPay("1001", KEY)
        client.responses.append({"code": 1, "payurl": "https://api.kaipay.cn/checkout/x"})
        result = client.create_order({"out_trade_no": "A1", "name": "n", "money": "1", "notify_url": "https://x.example.com/n"})
        self.assertEqual(result["payurl"], "https://api.kaipay.cn/checkout/x")
        self.assertEqual(client.requests[0][1], "https://api.kaipay.cn/epay/mapi")
        client.responses.append({"code": -1, "msg": "签名验证失败"})
        with self.assertRaisesRegex(kpay.KPayError, "签名验证失败"):
            client.create_order({"out_trade_no": "A1", "name": "n", "money": "1", "notify_url": "https://x.example.com/n"})

    def test_hmac_vector(self):
        h = VECTORS["hmac"]
        headers = kpay.hmac_headers("ak", h["secret"], "POST", h["requestUri"], h["body"], h["timestamp"], h["nonce"])
        self.assertEqual(headers["X-KPay-Signature"], h["signature"])
        get = kpay.hmac_headers("ak", h["secret"], "GET", VECTORS["hmacGet"]["requestUri"], "", h["timestamp"], h["nonce"])
        self.assertEqual(get["X-KPay-Signature"], VECTORS["hmacGet"]["signature"])

    def test_refund_body_matches_vector(self):
        client = FakeKPay("1001", KEY, api_key="ak", api_secret=VECTORS["hmac"]["secret"])
        client.responses.append({"code": 0})
        client.refund("P20260930120000001", 1.5, "R2026093012345", "易支付后台退款")
        method, url, body, headers = client.requests[0]
        self.assertEqual(body, VECTORS["hmac"]["body"])
        self.assertEqual(url, "https://api.kaipay.cn/pay/api/order/refund")
        with self.assertRaises(kpay.KPayError):
            kpay.KPay("1001", KEY).refund("P1", 1, "R1")


if __name__ == "__main__":
    unittest.main()
