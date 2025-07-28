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
    GB_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP     -- 예약 등록일
);


CREATE TABLE Business_Hours (
    day ENUM('mon','tue','wed','thu','fri','sat','sun') PRIMARY KEY,
    open_time TIME NULL,
    close_time TIME NULL,
    is_closed BOOLEAN DEFAULT 0
);

INSERT INTO Business_Hours (day, open_time, close_time, is_closed) VALUES
('mon', '09:00:00', '18:00:00', 0),
('tue', '09:00:00', '18:00:00', 0),
('wed', '09:00:00', '18:00:00', 0),
('thu', '09:00:00', '18:00:00', 0),
('fri', '09:00:00', '18:00:00', 0),
('sat', '10:00:00', '16:00:00', 0),
('sun', NULL, NULL, 1);