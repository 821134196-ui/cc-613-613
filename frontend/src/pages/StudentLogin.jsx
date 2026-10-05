import { useState } from 'react'
import { useNavigate, Link } from 'react-router-dom'
import { api, setSession } from '../api.js'

export default function StudentLogin() {
  const [studentNo, setStudentNo] = useState('2023010101')
  const [password, setPassword] = useState('student123')
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)
  const navigate = useNavigate()

  const submit = async (e) => {
    e.preventDefault()
    setError('')
    setLoading(true)
    try {
      const d = await api('/student/login', {
        method: 'POST',
        auth: false,
        body: { student_no: studentNo.trim(), password },
      })
      setSession(d.token, 'student')
      navigate('/student')
    } catch (err) {
      setError(err.message)
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="card narrow">
      <h2>学生登录</h2>
      <p className="muted">使用学号与密码登录，仅可查询和操作本人的证件与申请。</p>
      <form onSubmit={submit} className="form">
        <label>
          学号
          <input value={studentNo} onChange={(e) => setStudentNo(e.target.value)} placeholder="完整学号" />
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
      <p className="muted small">
        演示账号：2023010101 / student123（其他同学：2023010202、2022030303）
      </p>
      <p className="muted small">
        无需登录即可使用 <Link to="/counter">窗口扫码核验</Link>
      </p>
    </div>
  )
}
