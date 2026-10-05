import { createHashRouter, Navigate } from 'react-router-dom'
import StudentLayout from './pages/student/StudentLayout.jsx'
import StudentLogin from './pages/student/Login.jsx'
import MyCards from './pages/student/MyCards.jsx'
import MyApplications from './pages/student/MyApplications.jsx'
import StaffLayout from './pages/staff/StaffLayout.jsx'
import StaffLogin from './pages/staff/Login.jsx'
import ScanVerify from './pages/staff/ScanVerify.jsx'
import ManageApplications from './pages/staff/ManageApplications.jsx'
import ManageStudents from './pages/staff/ManageStudents.jsx'
import Home from './pages/Home.jsx'

function studentGuard() {
  return localStorage.getItem('sid_student_token') ? null : <Navigate to="/student/login" replace />
}
function staffGuard() {
  return localStorage.getItem('sid_staff_token') ? null : <Navigate to="/staff/login" replace />
}

export const router = createHashRouter([
  { path: '/', element: <Home /> },
  {
    path: '/student',
    element: <StudentLayout />,
    children: [
      { index: true, element: <Navigate to="/student/cards" replace /> },
      { path: 'login', element: <StudentLogin /> },
      { path: 'cards', element: <MyCards />, loader: studentGuard },
      { path: 'applications', element: <MyApplications />, loader: studentGuard },
    ],
  },
  {
    path: '/staff',
    element: <StaffLayout />,
    children: [
      { index: true, element: <Navigate to="/staff/scan" replace /> },
      { path: 'login', element: <StaffLogin /> },
      { path: 'scan', element: <ScanVerify />, loader: staffGuard },
      { path: 'applications', element: <ManageApplications />, loader: staffGuard },
      { path: 'students', element: <ManageStudents />, loader: staffGuard },
    ],
  },
  { path: '*', element: <Navigate to="/" replace /> },
])
