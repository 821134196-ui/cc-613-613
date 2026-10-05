export const CARD_STATUS = {
  active: { label: '有效', cls: 'ok' },
  lost: { label: '已挂失', cls: 'bad' },
  replaced: { label: '旧证已失效', cls: 'muted' },
}

export const APP_STATUS = {
  pending: { label: '待受理', cls: 'warn' },
  processing: { label: '制证中', cls: 'warn' },
  completed: { label: '已完成', cls: 'ok' },
  rejected: { label: '已驳回', cls: 'bad' },
}

export const ENROLLMENT_STATUS = {
  enrolled: { label: '在读', cls: 'ok' },
  suspended: { label: '休学', cls: 'warn' },
}

export const VERDICT_REASONS = {
  ok: '在读且学生证有效，可以享受优惠。',
  card_lost: '该学生证已挂失，自挂失时起不可使用。',
  card_replaced: '这是已失效的旧学生证，补证完成后旧证号永久失效，请使用新证。',
  not_enrolled: '该学生当前为休学状态，在读优惠资格中止，不能享受优惠。',
  card_not_found: '未查到该学生证（二维码无效或证号不存在），不能享受优惠。',
}
