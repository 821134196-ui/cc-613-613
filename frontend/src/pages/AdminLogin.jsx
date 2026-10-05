import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api, setSession } from '../api.js'

export default function AdminLogin() {
  const [email, setEmail] = useState('admin@school.edu.cn')
  const [password, setPassword] = useState('admin123')
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)
  const navigate = useNavigate()

  const submit = async (e) => {
    e.preventDefault()
    setLoading(true)
    setError('')
    try {
      const d = await api('/admin/login', { method: 'POST', auth: false, body: { email, password } })
      setSession(d.token, 'admin')
      navigate('/admin')
    } catch (err) {
      setError(err.message)
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="card narrow">
      <h2>教务管理登录</h2>
      <p className="muted">受理补证申请、维护学籍状态（休学 / 恢复在读）。</p>
      <form onSubmit={submit} className="form">
        <label>
          账号
          <input value={email} onChange={(e) => setEmail(e.target.value)} />
        </label>
        <label>
          密码
          <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} />
        </label>
        {error && <div className="alert err">{error}</div>}
        <button className="btn primary" disabled={loading}>
          {loading ? '登录中…' : '登录'}
        </button>
      </form>
      <p className="muted small">演示账号：admin@school.edu.cn / admin123</p>
    </div>
  )
}
