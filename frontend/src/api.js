// 极简 API 客户端：自动带上学生/教务令牌，统一 JSON 错误处理。
const TOKEN_KEY = 'campus_token'
const ROLE_KEY = 'campus_role'

export function getToken() {
  return localStorage.getItem(TOKEN_KEY) || ''
}
export function getRole() {
  return localStorage.getItem(ROLE_KEY) || ''
}
export function setSession(token, role) {
  localStorage.setItem(TOKEN_KEY, token)
  localStorage.setItem(ROLE_KEY, role)
}
export function clearSession() {
  localStorage.removeItem(TOKEN_KEY)
  localStorage.removeItem(ROLE_KEY)
}

export async function api(path, { method = 'GET', body = null, auth = true } = {}) {
  const headers = { Accept: 'application/json' }
  if (body !== null) headers['Content-Type'] = 'application/json'
  if (auth && getToken()) headers.Authorization = `Bearer ${getToken()}`

  const res = await fetch(`/api${path}`, {
    method,
    headers,
    body: body !== null ? JSON.stringify(body) : undefined,
  })

  const data = await res.json().catch(() => ({}))

  if (!res.ok) {
    const msg =
      data?.message ||
      Object.values(data?.errors || {})?.[0]?.[0] ||
      `请求失败（${res.status}）`
    const err = new Error(Array.isArray(msg) ? msg.join('；') : msg)
    err.status = res.status
    err.data = data
    throw err
  }
  return data
}
