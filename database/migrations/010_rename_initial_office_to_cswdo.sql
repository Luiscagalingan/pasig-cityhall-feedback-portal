USE pasig_feedback_portal;

UPDATE offices
SET name='Pasig City Social Welfare and Development Office',
    code='CSWDO',
    description='Pasig City Social Welfare and Development Office client feedback survey.'
WHERE code IN ('BHC','CHO');

UPDATE users
SET full_name='CSWDO Office Head', username='cswdo_head', email='cswdo.head@pasig.local'
WHERE username='cho_head';

UPDATE users
SET full_name='CSWDO Office Staff', username='cswdo_staff', email='cswdo.staff@pasig.local'
WHERE username='cho_staff';
