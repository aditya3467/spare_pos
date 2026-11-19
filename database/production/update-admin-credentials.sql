-- Select the production database before running. No SUPER/trigger privileges required.
-- Updates only the original superadmin imported from the local project.
-- The password is stored as a hash; existing sessions are invalidated.
UPDATE users
SET email = 'admin@arvam.in',
    password_hash = '$2y$10$v8c5AAhzIjDMb3axEFNuHeNXmoWXzCMIIVAv/5kc8SaracLHeIurO',
    session_version = session_version + 1
WHERE role = 'superadmin' AND email = 'admin@spare.test';

SELECT id, email, role, active FROM users WHERE role = 'superadmin' AND email = 'admin@arvam.in';
