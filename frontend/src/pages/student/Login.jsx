import { useState } from 'react'
import { useNavigate, useOutletContext } from 'react-router-dom'
import { studentApi, apiError } from '../../api.js'
import { useToast } from '../../toast.jsx'

export default function Login() {
  const navigate = useNavigate()
  const toast = useToast()
  const { setStudent } = useOutletContext()
  const [studentNumber, setStudentNumber] = useState('2023010001')
  const [password, setPassword] = useState('student123')
  const [loading, setLoading] = useState(false)

  async function submit(e) {
    e.preventDefault()
    setLoading(true)
    try {
      const { data } = await studentApi.post('/student/auth/login', { student_number: studentNumber, password })
      localStorage.setItem('sid_student_token', data.token)
      localStorage.setItem('sid_student', JSON.stringify(data.student))
      setStudent?.(data.student)
      toast('登录成功')
      navigate('/student/cards')
    } catch (err) {
      toast(apiError(err), 'bad')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div style={{ maxWidth: 380, margin: '60px auto' }}>
      <div className="card-box">
        <h3>学生登录</h3>
        <form onSubmit={submit} className="grid2" style={{ gridTemplateColumns: '1fr' }}>
          <div>
            <label>学号</label>
            <input value={studentNumber} onChange={(e) => setStudentNumber(e.target.value)} style={{ width: '100%' }} />
          </div>
          <div>
            <label>密码</label>
            <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} style={{ width: '100%' }} />
          </div>
          <button className="primary" disabled={loading}>{loading ? '登录中…' : '登录'}</button>
        </form>
        <p className="muted small" style={{ marginTop: 14 }}>
          演示账号：2023010001 / 2023010002 / 2023010003 / 2023010004，密码 student123
        </p>
      </div>
    </div>
  )
}
