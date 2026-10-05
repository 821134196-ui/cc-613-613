import { useEffect, useState, useCallback } from 'react'
import { staffApi, apiError } from '../../api.js'
import { APP_STATUS } from '../../labels.js'
import { useToast } from '../../toast.jsx'

const FILTERS = [
  { key: '', label: '全部' },
  { key: 'pending', label: '待受理' },
  { key: 'processing', label: '制证中' },
  { key: 'completed', label: '已完成' },
  { key: 'rejected', label: '已驳回' },
]

export default function ManageApplications() {
  const [items, setItems] = useState(null)
  const [filter, setFilter] = useState('')
  const [busyId, setBusyId] = useState(null)
  const toast = useToast()

  const load = useCallback(async () => {
    const { data } = await staffApi.get('/staff/applications', { params: filter ? { status: filter } : {} })
    setItems(data.data)
  }, [filter])

  useEffect(() => {
    load().catch((err) => toast(apiError(err), 'bad'))
  }, [load])

  async function act(application, action, confirmText) {
    if (confirmText && !window.confirm(confirmText)) return
    setBusyId(application.id)
    try {
      const { data } = await staffApi.post(`/staff/applications/${application.id}/${action}`)
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
      <h3>补证申请受理</h3>
      <p className="muted small">
        完成补证时系统在同一事务内终结旧证（旧证号永久失效）并签发新证；并发重复操作只有一笔生效。
      </p>
      <div className="row" style={{ marginBottom: 12 }}>
        {FILTERS.map((f) => (
          <button
            key={f.key}
            className={filter === f.key ? 'primary' : 'ghost'}
            onClick={() => setFilter(f.key)}
          >
            {f.label}
          </button>
        ))}
      </div>

      {!items ? (
        <p className="muted">加载中…</p>
      ) : items.length === 0 ? (
        <p className="muted">没有符合条件的申请。</p>
      ) : (
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>学生</th>
              <th>掩码学号</th>
              <th>在读状态</th>
              <th>旧证号</th>
              <th>旧证状态</th>
              <th>进度</th>
              <th>提交时间</th>
              <th>操作</th>
            </tr>
          </thead>
          <tbody>
            {items.map((a) => {
              const st = APP_STATUS[a.status] || { label: a.status, cls: 'muted' }
              const finished = ['completed', 'rejected'].includes(a.status)
              return (
                <tr key={a.id}>
                  <td>{a.id}</td>
                  <td>{a.student.name}</td>
                  <td>{a.student.student_number_masked}</td>
                  <td>
                    <span className={`badge ${a.student.enrollment_status === 'enrolled' ? 'ok' : 'warn'}`}>
                      {a.student.enrollment_status === 'enrolled' ? '在读' : '休学'}
                    </span>
                  </td>
                  <td>{a.lost_card_no}</td>
                  <td>
                    <span className={`badge ${a.lost_card_status === 'active' ? 'ok' : a.lost_card_status === 'lost' ? 'bad' : 'muted'}`}>
                      {a.lost_card_status === 'active' ? '有效' : a.lost_card_status === 'lost' ? '已挂失' : '旧证已失效'}
                    </span>
                  </td>
                  <td><span className={`badge ${st.cls}`}>{st.label}</span></td>
                  <td>{a.submitted_at}</td>
                  <td>
                    {!finished && (
                      <div className="row" style={{ gap: 6 }}>
                        {a.status === 'pending' && (
                          <button
                            className="ghost"
                            disabled={busyId === a.id}
                            onClick={() => act(a, 'processing', null)}
                          >
                            开始制证
                          </button>
                        )}
                        <button
                          className="primary"
                          disabled={busyId === a.id}
                          onClick={() => act(a, 'complete', '确认完成补证？完成后旧证号立即永久失效，新证即刻生效。')}
                        >
                          完成补证
                        </button>
                        <button
                          className="ghost"
                          disabled={busyId === a.id}
                          onClick={() => act(a, 'reject', '确认驳回该申请？')}
                        >
                          驳回
                        </button>
                      </div>
                    )}
                    {finished && <span className="muted small">—</span>}
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      )}
    </div>
  )
}
