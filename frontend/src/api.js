import axios from 'axios'

/**
 * 两个独立的 axios 实例：学生端与窗口端 token 分开存储，互不串用。
 */
function makeClient(tokenKey) {
  const client = axios.create({ baseURL: '/api' })

  client.interceptors.request.use((config) => {
    const token = localStorage.getItem(tokenKey)
    if (token) {
      config.headers.Authorization = `Bearer ${token}`
    }
    return config
  })

  client.interceptors.response.use(
    (res) => res,
    (error) => {
      if (error.response?.status === 401) {
        localStorage.removeItem(tokenKey)
        if (!location.hash.includes('login')) {
          const suffix = tokenKey === 'sid_student_token' ? '#/student/login' : '#/staff/login'
          location.hash = suffix
        }
      }
      return Promise.reject(error)
    },
  )

  return client
}

export const studentApi = makeClient('sid_student_token')
export const staffApi = makeClient('sid_staff_token')

export function apiError(error) {
  return (
    error?.response?.data?.message ||
    Object.values(error?.response?.data?.errors || {})[0]?.[0] ||
    error?.message ||
    '请求失败，请稍后再试'
  )
}
