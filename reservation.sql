
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

-- 1) 고객 마스터: customers_info  (검색/수정의 단일 소스)
CREATE TABLE IF NOT EXISTS customers_info (
  id           BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  full_name    VARCHAR(100)  NOT NULL,
  email        VARCHAR(255)  NULL,
  phone        VARCHAR(50)   NULL,
  birthday     DATE          NULL,
  notes        TEXT          NULL,         -- 간단 메모(히스토리 필요하면 customer_notes 테이블 계속 사용)
  created_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_email (email),
  KEY idx_phone (phone),
  KEY idx_name  (full_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 2) 예약 테이블에 고객 FK만 "추가"
--    (기존 컬럼/데이터는 그대로 유지. UI에서는 앞으로 customer_id를 우선 사용)
-- 1) 컬럼 customer_id 없으면 추가
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'GB_Reservation'
    AND COLUMN_NAME = 'customer_id'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE GB_Reservation ADD COLUMN customer_id BIGINT UNSIGNED NULL AFTER GB_id;',
  'SELECT 1'
);
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- 2) 인덱스 idx_res_customer 없으면 생성
SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'GB_Reservation'
    AND INDEX_NAME = 'idx_res_customer'
);
SET @sql := IF(@idx_exists = 0,
  'CREATE INDEX idx_res_customer ON GB_Reservation (customer_id);',
  'SELECT 1'
);
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- 3) FK fk_res_customer 없으면 추가
SET @fk_exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'GB_Reservation'
    AND CONSTRAINT_NAME = 'fk_res_customer'
);
SET @sql := IF(@fk_exists = 0,
  'ALTER TABLE GB_Reservation
     ADD CONSTRAINT fk_res_customer
     FOREIGN KEY (customer_id) REFERENCES customers_info(id)
     ON DELETE SET NULL ON UPDATE CASCADE;',
  'SELECT 1'
);
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- 3) 이벤트 메타
CREATE TABLE IF NOT EXISTS events (
  id           BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  title        VARCHAR(150)        NOT NULL,
  event_date   DATE                NOT NULL,
  event_par    TINYINT UNSIGNED    NOT NULL DEFAULT 72 CHECK (event_par BETWEEN 60 AND 90),
  course_name  VARCHAR(120)        NULL,
  created_at   TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_event_date (event_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 4) 이벤트 신청(로스터 + PII 저장소) : 고객 선택 + 표시용 이름 스냅샷
CREATE TABLE IF NOT EXISTS event_registrations (
  id                  BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  event_id            BIGINT UNSIGNED NOT NULL,
  customer_id         BIGINT UNSIGNED NULL,
  full_name_snapshot  VARCHAR(100)    NOT NULL,
  created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_reg_event
    FOREIGN KEY (event_id) REFERENCES events(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_reg_customer
    FOREIGN KEY (customer_id) REFERENCES customers_info(id)
    ON DELETE SET NULL ON UPDATE CASCADE,

  KEY idx_event       (event_id),
  KEY idx_event_name  (event_id, full_name_snapshot)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 5) 스코어카드: 신청 레코드(registration_id)와 1:1(라운드 기준) 연결
CREATE TABLE IF NOT EXISTS scorecards (
  id               BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  registration_id  BIGINT UNSIGNED NOT NULL,
  round_no         TINYINT UNSIGNED NOT NULL DEFAULT 1,

  h1  TINYINT UNSIGNED NULL, h2  TINYINT UNSIGNED NULL, h3  TINYINT UNSIGNED NULL,
  h4  TINYINT UNSIGNED NULL, h5  TINYINT UNSIGNED NULL, h6  TINYINT UNSIGNED NULL,
  h7  TINYINT UNSIGNED NULL, h8  TINYINT UNSIGNED NULL, h9  TINYINT UNSIGNED NULL,
  h10 TINYINT UNSIGNED NULL, h11 TINYINT UNSIGNED NULL, h12 TINYINT UNSIGNED NULL,
  h13 TINYINT UNSIGNED NULL, h14 TINYINT UNSIGNED NULL, h15 TINYINT UNSIGNED NULL,
  h16 TINYINT UNSIGNED NULL, h17 TINYINT UNSIGNED NULL, h18 TINYINT UNSIGNED NULL,

  out_total  SMALLINT UNSIGNED
              GENERATED ALWAYS AS (
                COALESCE(h1,0)+COALESCE(h2,0)+COALESCE(h3,0)+COALESCE(h4,0)+COALESCE(h5,0)+
                COALESCE(h6,0)+COALESCE(h7,0)+COALESCE(h8,0)+COALESCE(h9,0)
              ) STORED,
  in_total   SMALLINT UNSIGNED
              GENERATED ALWAYS AS (
                COALESCE(h10,0)+COALESCE(h11,0)+COALESCE(h12,0)+COALESCE(h13,0)+COALESCE(h14,0)+
                COALESCE(h15,0)+COALESCE(h16,0)+COALESCE(h17,0)+COALESCE(h18,0)
              ) STORED,
  strokes    SMALLINT UNSIGNED
              GENERATED ALWAYS AS (out_total + in_total) STORED,

  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_sc_reg
    FOREIGN KEY (registration_id) REFERENCES event_registrations(id)
    ON DELETE CASCADE ON UPDATE CASCADE,

  UNIQUE KEY uq_registration_round (registration_id, round_no),
  KEY idx_registration (registration_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;