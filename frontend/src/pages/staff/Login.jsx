import { useState } from 'react'
import { useNavigate, useOutletContext } from 'react-router-dom'
import { staffApi, apiError } from '../../api.js'
import { useToast } from '../../toast.jsx'

export default function Login() {
  const navigate = useNavigate()
  const toast = useToast()
  const { setUser } = useOutletContext()
  const [email, setEmail] = useState('staff@example.edu.cn')
  const [password, setPassword] = useState('staff123')
  const [loading, setLoading] = useState(false)

  async function submit(e) {
    e.preventDefault()
    setLoading(true)
    try {
      const { data } = await staffApi.post('/staff/auth/login', { email, password })
      localStorage.setItem('sid_staff_token', data.token)
      localStorage.setItem('sid_staff', JSON.stringify(data.user))
      setUser?.(data.user)
      toast('登录成功')
      navigate('/staff/scan')
    } catch (err) {
      toast(apiError(err), 'bad')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div style={{ maxWidth: 380, margin: '60px auto' }}>
      <div className="card-box">
        <h3>窗口工作人员登录</h3>
        <form onSubmit={submit} className="grid2" style={{ gridTemplateColumns: '1fr' }}>
          <div>
            <label>账号邮箱</label>
            <input value={email} onChange={(e) => setEmail(e.target.value)} style={{ width: '100%' }} />
          </div>
          <div>
            <label>密码</label>
            <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} style={{ width: '100%' }} />
          </div>
          <button className="primary" disabled={loading}>{loading ? '登录中…' : '登录'}</button>
        </form>
        <p className="muted small" style={{ marginTop: 14 }}>
          演示账号：staff@example.edu.cn / staff123
        </p>
      </div>
    </div>
  )
}
