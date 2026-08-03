-- Backfill visible operational alerts for unresolved action items created before notifications were enabled.
INSERT INTO notifications (user_id,office_id,type,title,message,link_url,created_at)
SELECT u.id,a.office_id,'action','Action requires attention',
       CONCAT('Action #',a.id,': ',a.title,' — ',LEFT(COALESCE(a.details,''),500)),
       CASE WHEN u.role='admin' THEN 'admin/actions.php' ELSE 'office/actions.php' END,
       a.updated_at
FROM actions a
JOIN users u ON u.status='active'
 AND (u.role='admin' OR (u.office_id=a.office_id AND u.role IN ('office_head','office_staff')))
WHERE a.status IN ('needs_action','in_progress','pending_approval')
  AND NOT EXISTS (
    SELECT 1 FROM notifications n
    WHERE n.user_id=u.id AND n.type='action'
      AND n.message LIKE CONCAT('%Action #',a.id,':%')
  );
