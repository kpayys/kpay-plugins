import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { KPay, KPayError, sign, hmacHeaders } from './kpay.mjs'

const vectors = JSON.parse(readFileSync(new URL('../../tests/vectors.json', import.meta.url), 'utf8'))
const KEY = vectors.key

function fakeFetch(responses, calls) {
  return async (url, init) => {
    calls.push({ url, ...init })
    const body = responses.shift()
    return { text: async () => JSON.stringify(body) }
  }
}

test('签名向量', () => {
  for (const c of vectors.cases) assert.equal(sign(c.params, KEY), c.sign)
})

test('校验通知', () => {
  const data = { ...vectors.cases[2].params, sign: vectors.cases[2].sign, sign_type: 'MD5' }
  const kpay = new KPay({ pid: '1001', key: KEY })
  assert.equal(kpay.verifyNotify(data), true)
  assert.equal(kpay.verifyNotify({ ...data, sign: data.sign.toUpperCase() }), true)
  assert.equal(kpay.verifyNotify({ ...data, money: '1.00' }), false)
  assert.equal(new KPay({ pid: '1002', key: KEY }).verifyNotify(data), false)
})

test('跳转下单地址', () => {
  const kpay = new KPay({ pid: '1001', key: KEY, gateway: 'https://api.kaipay.cn/epay/' })
  const url = kpay.payUrl({ out_trade_no: 'A1', name: '会员 & 充值', money: '9.9', notify_url: 'https://shop.example.com/n', type: 'alipay' })
  assert.ok(url.startsWith('https://api.kaipay.cn/epay/submit?'))
  const params = Object.fromEntries(new URL(url).searchParams)
  assert.equal(params.money, '9.90')
  assert.equal(sign(params, KEY), params.sign)
  assert.throws(() => kpay.payUrl({ out_trade_no: 'A1' }), KPayError)
})

test('服务端下单和报错', async () => {
  const calls = []
  const kpay = new KPay({ pid: '1001', key: KEY, fetch: fakeFetch([{ code: 1, payurl: 'https://api.kaipay.cn/checkout/x' }, { code: -1, msg: '签名验证失败' }], calls) })
  const order = { out_trade_no: 'A1', name: 'n', money: '1', notify_url: 'https://x.example.com/n' }
  assert.equal((await kpay.createOrder(order)).payurl, 'https://api.kaipay.cn/checkout/x')
  assert.equal(calls[0].url, 'https://api.kaipay.cn/epay/mapi')
  await assert.rejects(kpay.createOrder(order), /签名验证失败/)
})

test('HMAC 向量', () => {
  const h = vectors.hmac
  assert.equal(hmacHeaders('ak', h.secret, 'POST', h.requestUri, h.body, h.timestamp, h.nonce)['X-KPay-Signature'], h.signature)
  assert.equal(hmacHeaders('ak', h.secret, 'GET', vectors.hmacGet.requestUri, '', h.timestamp, h.nonce)['X-KPay-Signature'], vectors.hmacGet.signature)
})

test('退款请求体与向量一致', async () => {
  const calls = []
  const kpay = new KPay({ pid: '1001', key: KEY, apiKey: 'ak', apiSecret: vectors.hmac.secret, fetch: fakeFetch([{ code: 0 }], calls) })
  await kpay.refund({ tradeNo: 'P20260930120000001', amount: 1.5, refundRequestNo: 'R2026093012345', reason: '易支付后台退款' })
  assert.equal(calls[0].body, vectors.hmac.body)
  assert.equal(calls[0].url, 'https://api.kaipay.cn/pay/api/order/refund')
  await assert.rejects(new KPay({ pid: '1', key: 'k' }).refund({ tradeNo: 'P', amount: 1, refundRequestNo: 'R' }), KPayError)
})
