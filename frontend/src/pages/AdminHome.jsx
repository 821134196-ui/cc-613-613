import { useCallback, useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api, getToken, clearSession } from '../api.js'

const ENROLL_LABEL = { active: '在读', suspended: '休学', graduated: '毕业' }
const REQ_STATUS = { pending: '办理中', approved: '已办结', rejected: '已驳回' }

export default function AdminHome() {
  const [requests, setRequests] = useState([])
  const [students, setStudents] = useState([])
  const [msg, setMsg] = useState('')
  const [err, setErr] = useState('')
  const navigate = useNavigate()

  const refresh = useCallback(async () => {
    if (!getToken()) {
      navigate('/admin/login')
      return
    }
    try {
      const [r, s] = await Promise.all([api('/admin/requests'), api('/admin/students')])
      setRequests(r.requests)
      setStudents(s.students)
    } catch (e) {
      if (e.status === 401 || e.status === 403) {
        clearSession()
        navigate('/admin/login')
      } else setErr(e.message)
    }
  }, [navigate])

  useEffect(() => {
    refresh()
  }, [refresh])

  const act = async (fn, ok) => {
    setMsg('')
    setErr('')
    try {
      const d = await fn()
      setMsg(d.message || ok)
      await refresh()
    } catch (e) {
      setErr(e.message)
    }
  }

  const setEnrollment = (student, status) =>
    act(
      () =>
        api(`/admin/students/${student.id}/enrollment`, { method: 'POST', body: { status } }),
      '学籍状态已更新（证件状态不变）',
    )

  const logout = () => {
    clearSession()
    navigate('/admin/login')
  }

  return (
    <div className="stack">
      <div className="card row-between">
        <h2 className="no-margin">教务受理台</h2>
        <button className="btn-ghost" onClick={logout}>
          退出
        </button>
      </div>

      {msg && <div className="alert ok">{msg}</div>}
      {err && <div className="alert err">{err}</div>}

      <section className="card">
        <h3>补证申请（{requests.filter((r) => r.type === 'reissue').length}）</h3>
        <table className="table">
          <thead>
            <tr>
              <th>学生</th>
              <th>类型</th>
              <th>状态</th>
              <th>旧证</th>
              <th>原因/电话</th>
              <th>操作</th>
            </tr>
          </thead>
          <tbody>
            {requests.map((r) => (
              <tr key={r.id}>
                <td>
                  {r.student?.name}
                  <span className="muted small"> {r.student?.student_no}</span>
                </td>
                <td>{r.type === 'reissue' ? '补证' : '挂失'}</td>
                <td>
                  <span className="pill" data-status={r.status}>
                    {REQ_STATUS[r.status]}
                  </span>
                </td>
                <td>{r.card ? `${r.card.card_no}（${r.card.status}）` : '—'}</td>
                <td className="small">
                  {r.reason || '—'}
                  {r.contact_phone ? ` · ${r.contact_phone}` : ''}
                </td>
                <td>
                  {r.type === 'reissue' && r.status === 'pending' && (
                    <div className="btn-group">
                      <button
                        className="btn primary small"
                        onClick={() =>
                          act(() => api(`/admin/requests/${r.id}/approve`, { method: 'POST' }))
                        }
                      >
                        受理制证
                      </button>
                      <button
                        className="btn danger small"
                        onClick={() =>
                          act(() =>
                            api(`/admin/requests/${r.id}/reject`, {
                              method: 'POST',
                              body: { reject_reason: '材料不齐全' },
                            }),
                          )
                        }
                      >
                        驳回
                      </button>
                    </div>
                  )}
                  {r.status !== 'pending' && <span className="muted small">已处理</span>}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </section>

      <section className="card">
        <h3>学籍管理</h3>
        <p className="muted small">
          恢复在读只恢复优惠资格；已挂失/替代的旧证不受影响，不会被恢复。
        </p>
        <table className="table">
          <thead>
            <tr>
              <th>学号</th>
              <th>姓名</th>
              <th>院系</th>
              <th>学籍</th>
              <th>有效证件数</th>
              <th>操作</th>
            </tr>
          </thead>
          <tbody>
            {students.map((s) => (
              <tr key={s.id}>
                <td>{s.student_no}</td>
                <td>{s.name}</td>
                <td>{s.college}</td>
                <td>
                  <span className="pill" data-status={s.enrollment_status}>
                    {ENROLL_LABEL[s.enrollment_status]}
                  </span>
                </td>
                <td>{s.active_cards}</td>
                <td>
                  <div className="btn-group">
                    {s.enrollment_status !== 'active' && (
                      <button className="btn primary small" onClick={() => setEnrollment(s, 'active')}>
                        恢复在读
                      </button>
                    )}
                    {s.enrollment_status !== 'suspended' && (
                      <button className="btn warn small" onClick={() => setEnrollment(s, 'suspended')}>
                        休学
                      </button>
                    )}
                    {s.enrollment_status !== 'graduated' && (
                      <button className="btn-ghost small" onClick={() => setEnrollment(s, 'graduated')}>
                        毕业
                      </button>
                    )}
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </section>
    </div>
  )
}
