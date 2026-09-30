// KPay Node.js SDK（单文件，无依赖，Node 18+，使用内置 fetch 和 crypto）
//
//   import { KPay } from './kpay.mjs'
//   const kpay = new KPay({ pid: '1001', key: 'EPay Key' })
//   const url = kpay.payUrl({ out_trade_no: 'A1', name: '会员', money: '9.90',
//     notify_url: 'https://你的域名/notify', return_url: 'https://你的域名/done' })
//   // 异步通知：kpay.verifyNotify(query) 为 true 且 trade_status === 'TRADE_SUCCESS' 时入账，返回纯文本 success

import { createHash, createHmac, randomBytes, timingSafeEqual } from 'node:crypto'

export const DEFAULT_GATEWAY = 'https://api.kaipay.cn/'
const ORDER_FIELDS = ['type', 'out_trade_no', 'notify_url', 'return_url', 'name', 'money', 'device']

export class KPayError extends Error {
  constructor(message, response = {}) {
    super(message)
    this.name = 'KPayError'
    this.response = response
  }
}

/** EPay V1 签名：去掉 sign、sign_type 和空值，按参数名升序拼成 a=1&b=2，直接接上密钥，取 MD5 小写 */
export function sign(params, key) {
  const pairs = Object.keys(params)
    .filter((name) => name !== 'sign' && name !== 'sign_type')
    .filter((name) => {
      const value = params[name]
      return value !== null && value !== undefined && typeof value !== 'object' && String(value).trim() !== ''
    })
    .sort((a, b) => Buffer.compare(Buffer.from(a), Buffer.from(b)))
    .map((name) => `${name}=${params[name]}`)
  return createHash('md5').update(pairs.join('&') + key, 'utf8').digest('hex')
}

export function normalizeGateway(url = '') {
  const value = String(url).trim()
  if (!value || !/^https?:\/\/[^/\s]+/i.test(value)) return DEFAULT_GATEWAY
  return value.replace(/\/+$/, '').replace(/\/epay$/i, '') + '/'
}

/** 平台 API 的 HMAC 请求头。签名原文：METHOD \n PATH?QUERY \n 时间戳 \n nonce \n SHA256(请求体) */
export function hmacHeaders(apiKey, apiSecret, method, requestUri, body, timestamp, nonce) {
  const ts = timestamp ?? String(Math.floor(Date.now() / 1000))
  const n = nonce ?? randomBytes(16).toString('hex')
  const bodyHash = createHash('sha256').update(body ?? '', 'utf8').digest('hex')
  const canonical = [method.toUpperCase(), requestUri, ts, n, bodyHash].join('\n')
  return {
    'X-API-Key': apiKey,
    'X-KPay-Timestamp': ts,
    'X-KPay-Nonce': n,
    'X-KPay-Body-SHA256': bodyHash,
    'X-KPay-Signature-Method': 'HMAC-SHA256',
    'X-KPay-Signature': createHmac('sha256', apiSecret).update(canonical, 'utf8').digest('hex'),
  }
}

export class KPay {
  /**
   * @param {{pid: string, key: string, gateway?: string, apiKey?: string, apiSecret?: string, fetch?: typeof fetch}} options
   *   pid：商户ID（KPay「EPay 接入 → EPay 配置」）；key：EPay Key（「API 密钥」页创建 EPay 兼容密钥时显示）；
   *   apiKey / apiSecret：选填，平台 API 密钥（退款用）
   */
  constructor({ pid, key, gateway = '', apiKey = '', apiSecret = '', fetch: fetchImpl } = {}) {
    this.pid = String(pid ?? '').trim()
    this.key = String(key ?? '').trim()
    this.gateway = normalizeGateway(gateway)
    this.apiKey = String(apiKey).trim()
    this.apiSecret = String(apiSecret).trim()
    this.fetch = fetchImpl ?? globalThis.fetch
  }

  signOrder(order) {
    if (!this.pid || !this.key) throw new KPayError('商户ID或 EPay Key 未配置')
    for (const field of ['out_trade_no', 'name', 'money', 'notify_url']) {
      if (order[field] === undefined || String(order[field]).trim() === '') throw new KPayError(`缺少参数 ${field}`)
    }
    const params = { pid: this.pid }
    for (const field of ORDER_FIELDS) {
      if (order[field] !== undefined && order[field] !== null && String(order[field]).trim() !== '') params[field] = String(order[field])
    }
    params.money = Number(params.money).toFixed(2)
    params.sign = sign(params, this.key)
    params.sign_type = 'MD5'
    return params
  }

  /** 浏览器跳转下单的地址（GET /epay/submit） */
  payUrl(order) {
    return `${this.gateway}epay/submit?${new URLSearchParams(this.signOrder(order))}`
  }

  /** 服务端下单（POST /epay/mapi），返回里的 payurl 是付款页地址 */
  async createOrder(order) {
    const result = await this.#request('POST', `${this.gateway}epay/mapi`, new URLSearchParams(this.signOrder(order)).toString(), {
      'Content-Type': 'application/x-www-form-urlencoded',
    })
    if (Number(result.code) !== 1) throw new KPayError(result.msg || '下单失败', result)
    return result
  }

  async queryOrder({ tradeNo = '', outTradeNo = '' } = {}) {
    const query = new URLSearchParams({ act: 'order', pid: this.pid, key: this.key })
    if (tradeNo) query.set('trade_no', tradeNo)
    else query.set('out_trade_no', outTradeNo)
    const result = await this.#request('GET', `${this.gateway}epay/api?${query}`, undefined, {})
    if (Number(result.code) !== 1) throw new KPayError(result.msg || '查询失败', result)
    return result
  }

  /** 校验异步通知 / 同步跳转带回的参数（签名 + 商户ID）。入账前还要核对 money 和订单金额 */
  verifyNotify(data) {
    if (!this.pid || !this.key || typeof data?.sign !== 'string') return false
    if (String(data.pid ?? '') !== this.pid) return false
    const expected = Buffer.from(sign(data, this.key))
    const actual = Buffer.from(data.sign.trim().toLowerCase())
    return expected.length === actual.length && timingSafeEqual(expected, actual)
  }

  /** 退款（需要勾选「发起退款」权限的平台 API 密钥）。同一笔退款重试时 refundRequestNo 保持不变 */
  async refund({ tradeNo, amount, refundRequestNo, reason = '' }) {
    if (!this.apiKey || !this.apiSecret) throw new KPayError('未配置平台 API 密钥')
    const path = '/pay/api/order/refund'
    const body = JSON.stringify({ orderNo: tradeNo, refundAmount: Math.round(Number(amount) * 100) / 100, refundRequestNo, reason })
    const result = await this.#request('POST', this.gateway.replace(/\/$/, '') + path, body, {
      'Content-Type': 'application/json',
      ...hmacHeaders(this.apiKey, this.apiSecret, 'POST', path, body),
    })
    if (Number(result.code) !== 0) throw new KPayError(result.msg || '退款失败', result)
    return result
  }

  async #request(method, url, body, headers) {
    let response
    try {
      response = await this.fetch(url, { method, body, headers: { Accept: 'application/json', ...headers } })
    } catch (error) {
      throw new KPayError(`连接 KPay 失败：${error.message}`)
    }
    const text = await response.text()
    try {
      const result = JSON.parse(text)
      if (result && typeof result === 'object') return result
    } catch {}
    throw new KPayError('KPay 返回数据无法解析')
  }
}
