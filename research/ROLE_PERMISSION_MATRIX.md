# Role-Permission Matrix

| Capability | Public | Office Staff | Office Head | Administrator |
|---|:---:|:---:|:---:|:---:|
| Submit 18+ feedback | Yes | Yes | Yes | Yes |
| View assigned-office dashboard | No | Yes | Yes | No |
| View consolidated dashboard | No | No | No | Yes |
| Search assigned-office feedback | No | Yes | Yes | No |
| View all active-office feedback | No | No | No | Yes |
| Update action investigation | No | Yes | Yes | Monitor only |
| Request action completion | No | Yes | Yes | Monitor only |
| Approve final completion | No | No | Yes | Monitor only |
| Review/correct sentiment | No | No | Yes | Yes |
| Import historical CSV | No | No | Yes | No |
| Manage assigned-office staff | No | No | Yes | Yes |
| Manage offices and heads | No | No | No | Yes |
| Create announcements | No | No | No | Yes |
| View system audit | No | No | No | Yes |
| Create encrypted backup | No | No | No | Yes |
| Apply retention policy | No | No | No | Yes |

All office queries are scoped by the authenticated user's `office_id`. Archived accounts and users assigned to archived offices cannot sign in.
