DROP TABLE IF EXISTS GB_Reservation;
DROP TABLE IF EXISTS business_hours_weekly;
DROP TABLE IF EXISTS business_hours_special;
DROP TABLE IF EXISTS customer_notes;


CREATE TABLE GB_Reservation (
    GB_id INT AUTO_INCREMENT PRIMARY KEY,                 -- 예약 번호
    GB_date DATE NOT NULL,                                -- 예약 날짜
    GB_room_no INT NOT NULL,                              -- 룸 번호
    GB_start_time TIME NOT NULL,                          -- 시작 시간
    GB_end_time TIME NOT NULL,                            -- 끝나는 시간
    GB_name VARCHAR(100) NOT NULL,                        -- 이름
    GB_email VARCHAR(100),                                -- 이메일
    GB_phone VARCHAR(20),                                 -- 전화번호
    GB_consent TINYINT(1) DEFAULT 0,                      -- 개인정보 동의 (0=No, 1=Yes)
    GB_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,     -- 예약 등록일
    Group_id VARCHAR(255) DEFAULT NULL,
    GB_ip   VARCHAR(45) DEFAULT NULL                        -- 예약 등록 IP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE business_hours_weekly (
    weekday ENUM('mon','tue','wed','thu','fri','sat','sun') PRIMARY KEY,
    open_time TIME NOT NULL,
    close_time TIME NOT NULL,
    is_closed TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE business_hours_special (
    date DATE PRIMARY KEY,
    open_time TIME NOT NULL,
    close_time TIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Customer memo

CREATE TABLE customer_notes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name  VARCHAR(100) NOT NULL,
  phone VARCHAR(20)  NOT NULL,
  email VARCHAR(100) NOT NULL,
  note  TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_customer (name, phone, email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO business_hours_weekly (weekday, open_time, close_time, is_closed) VALUES
('mon', '09:00', '21:00', 0),
('tue', '09:00', '21:00', 0),
('wed', '09:00', '21:00', 0),
('thu', '09:00', '21:00', 0),
('fri', '09:00', '21:00', 0),
('sat', '10:00', '22:00', 0),
('sun', '10:00', '20:00', 0);

CREATE TABLE IF NOT EXISTS reservation_tokens (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  token           CHAR(43) NOT NULL UNIQUE,
  reservation_id  INT NULL,                -- GB_Reservation.GB_id (INT)와 맞춤
  group_id        VARCHAR(255) NULL,       -- GB_Reservation.Group_id와 동일
  action          ENUM('edit') NOT NULL DEFAULT 'edit',
  expires_at      DATETIME NOT NULL,
  used_at         DATETIME NULL,
  max_uses        TINYINT UNSIGNED NOT NULL DEFAULT 0,   -- 0=만료 전까지 재사용 OK
  uses            TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_ip      VARBINARY(16) NULL,
  INDEX idx_reservation (reservation_id),
  INDEX idx_group (group_id(64)),          -- uniqid 길이(≈23)면 64 prefix로 충분
  INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;