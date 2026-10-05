import { Routes, Route, NavLink, Navigate, useNavigate } from 'react-router-dom'
import { getRole, clearSession } from './api.js'
import StudentLogin from './pages/StudentLogin.jsx'
import StudentHome from './pages/StudentHome.jsx'
import CounterVerify from './pages/CounterVerify.jsx'
import AdminLogin from './pages/AdminLogin.jsx'
import AdminHome from './pages/AdminHome.jsx'

function NavBar() {
  const role = getRole()
  const navigate = useNavigate()
  const item = ({ isActive }) =>
    `nav-item ${isActive ? 'active' : ''}`

  const logout = () => {
    clearSession()
    navigate('/student/login')
    window.location.reload()
  }

  return (
    <header className="topbar">
      <div className="brand">🎓 高校学生证优惠核验系统</div>
      <nav className="nav">
        <NavLink to="/counter" className={item}>窗口扫码核验</NavLink>
        {role === 'student' && <NavLink to="/student" className={item}>学生端</NavLink>}
        {role !== 'student' && <NavLink to="/student/login" className={item}>学生登录</NavLink>}
        {role === 'admin' ? (
          <>
            <NavLink to="/admin" className={item}>教务受理</NavLink>
            <button className="btn-ghost" onClick={logout}>退出教务</button>
          </>
        ) : (
          <NavLink to="/admin/login" className={item}>教务登录</NavLink>
        )}
      </nav>
    </header>
  )
}

export default function App() {
  return (
    <div className="app">
      <NavBar />
      <main className="content">
        <Routes>
          <Route path="/" element={<Navigate to="/counter" replace />} />
          <Route path="/counter" element={<CounterVerify />} />
          <Route path="/student/login" element={<StudentLogin />} />
          <Route path="/student" element={<StudentHome />} />
          <Route path="/admin/login" element={<AdminLogin />} />
          <Route path="/admin" element={<AdminHome />} />
          <Route path="*" element={<Navigate to="/counter" replace />} />
        </Routes>
      </main>
      <footer className="foot">
        本地演示：Laravel + SQLite（WAL）· React · 扫码服务为本地模拟，核验结论以服务端实时状态为准
      </footer>
    </div>
  )
}
