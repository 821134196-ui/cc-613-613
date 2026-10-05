import { useState } from 'react'
import { useNavigate, NavLink, Outlet, useLocation } from 'react-router-dom'
import { staffApi } from '../../api.js'

export default function StaffLayout() {
  const navigate = useNavigate()
  const location = useLocation()
  const [user, setUser] = useState(() => {
    try {
      return JSON.parse(localStorage.getItem('sid_staff') || 'null')
    } catch {
      return null
    }
  })

  function logout() {
    localStorage.removeItem('sid_staff_token')
    localStorage.removeItem('sid_staff')
    navigate('/staff/login')
  }

  if (location.pathname === '/staff/login') {
    return <Outlet context={{ setUser }} />
  }

  return (
    <div className="staff-wrap">
      <div className="topbar staff">
        <div className="brand">🏫 窗口核验端</div>
        <nav>
          <NavLink to="/staff/scan">扫码核验</NavLink>
          <NavLink to="/staff/applications">补证受理</NavLink>
          <NavLink to="/staff/students">学籍管理</NavLink>
        </nav>
        {user && <span className="who">{user.name}</span>}
        <button onClick={logout}>退出</button>
      </div>
      <Outlet context={{ setUser, user }} />
    </div>
  )
}
