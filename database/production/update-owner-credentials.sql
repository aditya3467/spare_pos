-- Select the production database before running. No SUPER privilege required.
-- Updates the original shop owner only; admin and staff are unchanged.
UPDATE users
SET email = 'owner@arvam.in',
    password_hash = '$2y$10$W4UEYFKLuhHi5fVFw4ys2O1A2EvfA9BsR2qyXvOvuONT1ImnGZPOy',
    session_version = session_version + 1
WHERE role = 'shop_owner' AND email = 'owner@spare.test' AND shop_id = 1;

SELECT id,email,role,shop_id,active FROM users WHERE role='shop_owner' AND email='owner@arvam.in';
