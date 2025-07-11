<?php

 // DB 접속 정보
    $host = 'localhost';
    $db = 'golf_booking';
    $user = 'root';
    $pass = '8888';
    $charset = 'utf8mb4';

    // PDO 설정
    $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    try {
        $pdo = new PDO($dsn, $user, $pass, $options);
    } catch (PDOException $e) {
        echo "Error: ".$e->getMessage();
        exit();
    }

    // 클라이언트에서 전달된 날짜와 방 번호 받기
    $date = $_GET['date'] ?? null;
    $room = $_GET['room'] ?? null;

    // 유효성 검사
    if (!$date || !$room) {
        echo json_encode([]);
        exit;
    }

    $sql = "SELECT GB_start_time, GB_end_time, GB_room_no FROM gb_reservation WHERE GB_date = ? AND GB_room_no = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$date, $room]);

    $reservedTimes = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $reservedTimes[] = [
            'start_time' => substr($row['GB_start_time'], 0, 5),
            'end_time' => substr($row['GB_end_time'], 0, 5),
            'room_no' => $row['GB_room_no']
        ];
    }

    echo json_encode($reservedTimes);