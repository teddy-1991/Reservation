DROP TABLE IF EXISTS GB_Reservation;
DROP TABLE IF EXISTS Business_Hours;


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
    Group_id VARCHAR(255) DEFAULT NULL
);

CREATE TABLE business_hours (
  id INT AUTO_INCREMENT PRIMARY KEY,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  weekday ENUM('mon','tue','wed','thu','fri','sat','sun') NOT NULL,
  open_time TIME,         -- 휴무일 수도 있으므로 NOT NULL 제거
  close_time TIME,        -- 마찬가지
  closed TINYINT(1) DEFAULT 0  -- 0: 운영, 1: 휴무
);

INSERT INTO business_hours (start_date, end_date, weekday, open_time, close_time) VALUES
('2025-08-01', '2025-08-31', 'mon', '10:00:00', '22:00:00'),
('2025-08-01', '2025-08-31', 'tue', '10:00:00', '22:00:00'),
('2025-08-01', '2025-08-31', 'wed', '10:00:00', '22:00:00'),
('2025-08-01', '2025-08-31', 'thu', '10:00:00', '22:00:00'),
('2025-08-01', '2025-08-31', 'fri', '10:00:00', '22:00:00'),
('2025-08-01', '2025-08-31', 'sat', '12:00:00', '20:00:00'),
('2025-08-01', '2025-08-31', 'sun', '12:00:00', '20:00:00');