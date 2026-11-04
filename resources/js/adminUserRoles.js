export const ADMIN_USER_ROLE_LABELS = ['User', 'Creator', 'Moderator', 'Admin', 'Super Admin', 'Owner'];

export function adminUserRoleValue(role) {
  return String(role || '').trim().toLowerCase().replace(/[_-]/g, ' ');
}

export function selectedAdminUserRole(row = {}) {
  const rawRole = adminUserRoleValue(row.raw?.role);
  const displayedRole = adminUserRoleValue(row.role);
  const validRoles = ADMIN_USER_ROLE_LABELS.map(adminUserRoleValue);
  return validRoles.includes(rawRole) ? rawRole : validRoles.includes(displayedRole) ? displayedRole : 'user';
}
