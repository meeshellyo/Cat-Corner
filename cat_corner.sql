-- tables needed for cat corner
DROP TABLE IF EXISTS notification;
DROP TABLE IF EXISTS flag;
DROP TABLE IF EXISTS media;
DROP TABLE IF EXISTS comment;
DROP TABLE IF EXISTS post_subcategory;
DROP TABLE IF EXISTS post;
DROP TABLE IF EXISTS subcategory;
DROP TABLE IF EXISTS main_category;
DROP TABLE IF EXISTS users;


-- An index is like a lookup table the database builds behind the scenes to speed up searches.
-- Without an index → the DB has to scan every row in the table to find what you want (called a full table scan).
-- With an index → the DB jumps straight to the matching rows, like looking things up in the index at the back of a book.

-- In SQL, a constraint is simply a rule you apply to a column or a table that the database will always enforce.
-- It’s how you tell the database: “this column must behave in a certain way.”


-- tables

-- users
CREATE TABLE users (
  user_id      INT AUTO_INCREMENT PRIMARY KEY,
  username     VARCHAR(50)  NOT NULL UNIQUE,
  email        VARCHAR(255) NOT NULL UNIQUE,
  hashed_pass  VARCHAR(255) NOT NULL,
  display_name VARCHAR(100),
  bio          TEXT,
  avatar_id    VARCHAR(255),
  status       ENUM('active','banned') NOT NULL DEFAULT 'active',
  role         ENUM('registered', 'moderator', 'superadmin') NOT NULL DEFAULT 'registered',
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- main categories (top-level tabs)
CREATE TABLE main_category (
  main_category_id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60) NOT NULL UNIQUE,
  slug VARCHAR(80) NOT NULL UNIQUE
);

-- subcategories (sub-tabs under a main category)
CREATE TABLE subcategory (
  subcategory_id   INT AUTO_INCREMENT PRIMARY KEY,
  main_category_id INT NOT NULL,
  name VARCHAR(60) NOT NULL,
  slug VARCHAR(80) NOT NULL,
  UNIQUE KEY uq_subcat_slug (slug),
  UNIQUE KEY uq_subcat_name_per_main (main_category_id, name),
  CONSTRAINT fk_subcat_main FOREIGN KEY (main_category_id) REFERENCES main_category(main_category_id) ON DELETE CASCADE
);


-- posts 
CREATE TABLE post (
  post_id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id         INT NOT NULL,  -- creator of post
  title           VARCHAR(200) NOT NULL,
  body            TEXT NOT NULL,
  content_status  ENUM('live','pending','flagged','rejected','deleted') NOT NULL DEFAULT 'live',
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_post_user (user_id),
  INDEX idx_post_status (content_status),
  CONSTRAINT fk_post_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
  );

-- many to many
CREATE TABLE post_subcategory (
  post_id        INT NOT NULL,
  subcategory_id INT NOT NULL,
  PRIMARY KEY (post_id, subcategory_id),
  INDEX idx_ps_subcat (subcategory_id),
  CONSTRAINT fk_ps_post FOREIGN KEY (post_id) REFERENCES post(post_id) ON DELETE CASCADE,
  CONSTRAINT fk_ps_subcat FOREIGN KEY (subcategory_id) REFERENCES subcategory(subcategory_id) ON DELETE CASCADE
);

-- comments
CREATE TABLE comment (
  comment_id      INT AUTO_INCREMENT PRIMARY KEY,
  post_id         INT NOT NULL,
  user_id         INT NOT NULL,  -- commenter
  body            TEXT NOT NULL,
  content_status  ENUM('live','flagged','deleted') NOT NULL DEFAULT 'live',
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_comment_post (post_id),
  INDEX idx_comment_user (user_id),
  CONSTRAINT fk_comment_post FOREIGN KEY (post_id) REFERENCES post(post_id) ON DELETE CASCADE,
  CONSTRAINT fk_comment_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- media attached to posts
CREATE TABLE media (
  media_id   INT AUTO_INCREMENT PRIMARY KEY,
  post_id    INT NOT NULL,
  filename   VARCHAR(255) NOT NULL,
  type       ENUM('image','video','gif','other') NOT NULL DEFAULT 'image',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_media_post (post_id),
  CONSTRAINT fk_media_post FOREIGN KEY (post_id) REFERENCES post(post_id) ON DELETE CASCADE
);


-- flags raised on posts
CREATE TABLE flag (
  flag_id                   INT AUTO_INCREMENT PRIMARY KEY,
  post_id                   INT NOT NULL, -- post that gets flagged

  trigger_source            ENUM('lexicon','manual') NOT NULL DEFAULT 'lexicon',
  flagged_by_id             INT NULL, -- mod who manually flags
  trigger_hits              INT NOT NULL DEFAULT 1, -- how many
  trigger_word              VARCHAR(255) NULL, -- words that were found


  status                    ENUM('flagged','approved','rejected') NOT NULL DEFAULT 'flagged',
  moderator_id              INT NULL,   -- who decided status
  decided_at                DATETIME NULL,

  -- media stuff
  media_status              ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  media_decided_by          INT NULL,      -- moderator who decided media
  media_decided_at          DATETIME NULL,

  notes                     TEXT,
  created_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_flag_post (post_id),
  INDEX idx_flag_status (status),
  INDEX idx_flag_media_status (media_status),
  INDEX idx_flag_final_mod (moderator_id),
  INDEX idx_flag_flagger (flagged_by_id),

 CONSTRAINT fk_flag_post
    FOREIGN KEY (post_id) REFERENCES post(post_id) ON DELETE CASCADE,
  CONSTRAINT fk_flag_final_mod
    FOREIGN KEY (moderator_id) REFERENCES users(user_id) ON DELETE SET NULL,
  CONSTRAINT fk_flag_flagged_by
    FOREIGN KEY (flagged_by_id) REFERENCES users(user_id) ON DELETE SET NULL,
  CONSTRAINT fk_flag_media_mod
    FOREIGN KEY (media_decided_by) REFERENCES users(user_id) ON DELETE SET NULL
    );

-- notifications
CREATE TABLE notification (
  notification_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id         INT NOT NULL,      -- recipient
  type            ENUM('new_comment','post_flagged','media_approved','mention','other') NOT NULL DEFAULT 'other',
  other_user_id   INT NULL,          -- other user (commenter)
  post_id         INT NULL,
  comment_id      INT NULL,
  media_id        INT NULL,
  message         VARCHAR(255) NULL,
  read_at         DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_notif_user (user_id),
  INDEX idx_notif_type (type),

  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  CONSTRAINT fk_notif_other_user FOREIGN KEY (other_user_id) REFERENCES users(user_id) ON DELETE SET NULL,
  CONSTRAINT fk_notif_post FOREIGN KEY (post_id) REFERENCES post(post_id) ON DELETE SET NULL,
  CONSTRAINT fk_notif_comment FOREIGN KEY (comment_id) REFERENCES comment(comment_id) ON DELETE SET NULL,
  CONSTRAINT fk_notif_media FOREIGN KEY (media_id) REFERENCES media(media_id) ON DELETE SET NULL
);
