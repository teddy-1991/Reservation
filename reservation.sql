CREATE TABLE GB_Reservation (
    GB_id INT AUTO_INCREMENT PRIMARY KEY,                 -- 예약 번호
    GB_date DATE NOT NULL,                                -- 예약 날짜
    GB_room_no INT NOT NULL,                              -- 룸 번호
    GB_start_time TIME NOT NULL,                          -- 시작 시간
    GB_end_time TIME NOT NULL,                            -- 끝나는 시간
    GB_num_guests INT,                                    -- 게스트 수
    GB_preferred_hand ENUM('Left', 'Right', 'Both'),      -- 손잡이 선호
    GB_name VARCHAR(100) NOT NULL,                        -- 이름
    GB_email VARCHAR(100),                                -- 이메일
    GB_phone VARCHAR(20),                                 -- 전화번호
    GB_consent TINYINT(1) DEFAULT 0,                      -- 개인정보 동의 (0=No, 1=Yes)
    GB_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP     -- 예약 등록일
);


CREATE TABLE Business_Hour (
    BH_id INT AUTO_INCREMENT PRIMARY KEY,
    BH_room_no INT NOT NULL,
    BH_start_time TIME NOT NULL,
    BH_end_time TIME NOT NULL
);