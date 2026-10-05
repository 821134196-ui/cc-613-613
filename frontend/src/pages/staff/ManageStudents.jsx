import { useEffect, useState, useCallback } from 'react'
import { staffApi, apiError } from '../../api.js'
import { useToast } from '../../toast.jsx'

/**
 * 学籍管理：休学 / 恢复在读。
 * 只变更在读资格，页面明确提示不会恢复任何已挂失 / 已失效的旧证。
 */
export default function ManageStudents() {
  const [students, setStudents] = useState(null)
  const [busyId, setBusyId] = useState(null)
  const toast = useToast()

  const load = useCallback(async () => {
    const { data } = await staffApi.get('/staff/students')
    setStudents(data.data)
  }, [])

  useEffect(() => {
    load().catch((err) => toast(apiError(err), 'bad'))
  }, [load])

  async function change(student, action, msg) {
    if (!window.confirm(msg)) return
    setBusyId(student.id)
    try {
      const { data } = await staffApi.post(`/staff/students/${student.id}/${action}`)
      toast(data.message || '操作成功')
      await load()
    } catch (err) {
      toast(apiError(err), 'bad')
    } finally {
      setBusyId(null)
    }
  }

  return (
    <div className="card-box">
      <h3>学籍（在读资格）管理</h3>
      <div className="privacy-note">
        休学 / 恢复只改变在读资格，不改变任何学生证的状态。恢复在读不会恢复已挂失的旧证，
        也不会让补证后的旧证号复活——学生需要凭当前有效证享受优惠。
      </div>
      {!students ? (
        <p className="muted">加载中…</p>
      ) : (
        <table>
          <thead>
            <tr>
              <th>姓名</th>
              <th>掩码学号</th>
              <th>学院</th>
              <th>在读资格</th>
              <th>证件数（含历史）</th>
              <th>操作</th>
            </tr>
          </thead>
          <tbody>
            {students.map((s) => (
              <tr key={s.id}>
                <td>{s.name}</td>
                <td>{s.student_number_masked}</td>
                <td>{s.college}</td>
                <td>
                  <span className={`badge ${s.enrollment_status === 'enrolled' ? 'ok' : 'warn'}`}>
                    {s.enrollment_status === 'enrolled' ? '在读' : '休学'}
                  </span>
                </td>
                <td>{s.cards_count}</td>
                <td>
                  {s.enrollment_status === 'enrolled' ? (
                    <button
                      className="ghost"
                      disabled={busyId === s.id}
                      onClick={() => change(s, 'suspend', `确认办理 ${s.name} 的休学？在读优惠资格将立即中止（证件状态不变）。`)}
                    >
                      办理休学
                    </button>
                  ) : (
                    <button
                      className="primary"
                      disabled={busyId === s.id}
                      onClick={() => change(s, 'resume', `确认恢复 ${s.name} 在读？仅恢复资格，已挂失/失效的旧证不会恢复。`)}
                    >
                      恢复在读
                    </button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  )
}
