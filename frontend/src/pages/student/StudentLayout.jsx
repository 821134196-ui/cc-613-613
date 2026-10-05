import { useState } from 'react'
import { useNavigate, NavLink, Outlet, useLocation } from 'react-router-dom'
import { studentApi } from '../../api.js'

export default function StudentLayout() {
  const navigate = useNavigate()
  const location = useLocation()
  const [student, setStudent] = useState(() => {
    try {
      return JSON.parse(localStorage.getItem('sid_student') || 'null')
    } catch {
      return null
    }
  })

  function logout() {
    studentApi.post('/student/auth/logout').catch(() => {})
    localStorage.removeItem('sid_student_token')
    localStorage.removeItem('sid_student')
    navigate('/student/login')
  }

  // 登录页不显示框架
  if (location.pathname === '/student/login') {
    return <Outlet context={{ setStudent }} />
  }

  return (
    <div className="student-wrap">
      <div className="topbar">
        <div className="brand">🎓 学生服务</div>
        <nav>
          <NavLink to="/student/cards">我的证件 / 挂失</NavLink>
          <NavLink to="/student/applications">补证与办理进度</NavLink>
        </nav>
        {student && (
          <span className="who">
            {student.name}（{student.student_number}）
          </span>
        )}
        <button onClick={logout}>退出</button>
      </div>
      <Outlet context={{ setStudent, student }} />
    </div>
  )
}
