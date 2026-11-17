/** Tabs of the "Users & roles" section and who may open them (UI gate; the API enforces). */
export const USERS_SECTIONS = [
  { to: '/users', label: 'Team', permissions: ['users.view', 'users.manage'], end: true },
  { to: '/users/invitations', label: 'Invitations', permissions: ['users.invite'], end: false },
  { to: '/users/roles', label: 'Roles', permissions: ['roles.manage'], end: false },
] as const

export const USERS_PERMISSIONS = ['users.view', 'users.manage', 'users.invite', 'roles.manage']
