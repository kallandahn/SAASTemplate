<?php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0); // Disable HTML error output

// Include database configuration
require_once 'onboarding/config.php';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e){
    http_response_code(500);
    echo json_encode(["error" => "Database connection failed: " . $e->getMessage()]);
    exit;
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

switch ($action) {

    case 'get_calendars':
        $stmt = $pdo->prepare("SELECT * FROM calendars");
        $stmt->execute();
        $calendars = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($calendars);
        break;

    case 'create_calendar':
        $name = $_POST['name'] ?? '';
        $color = $_POST['color'] ?? '#ffffff';
        $description = $_POST['description'] ?? '';
        $mainColor = $_POST['mainColor'] ?? '#27b6c1';
        $mainFontColor = $_POST['mainFontColor'] ?? '#ffffff';
        $secondaryFontColor = $_POST['secondaryFontColor'] ?? '#3e3e3e';
        $timezone = $_POST['timezone'] ?? 'America/New_York';

        if(!$name){
            http_response_code(400);
            echo json_encode(["error" => "Calendar name is required"]);
            exit;
        }
        $stmt = $pdo->prepare("INSERT INTO calendars (name, color, description, mainColor, mainFontColor, secondaryFontColor, timezone) VALUES (?,?,?,?,?,?,?)");
        $stmt->execute([$name, $color, $description, $mainColor, $mainFontColor, $secondaryFontColor, $timezone]);
        echo json_encode(["success" => true, "id" => $pdo->lastInsertId()]);
        break;

    case 'update_calendar':
        try {
            // Get POST data with defaults
            $id = $_POST['id'] ?? 0;
            $name = $_POST['name'] ?? '';
            $mainColor = $_POST['mainColor'] ?? null;
            $secondaryColor = $_POST['secondaryColor'] ?? null;
            $mainFontColor = $_POST['mainFontColor'] ?? null;
            $secondaryFontColor = $_POST['secondaryFontColor'] ?? null;

            // Validate required fields
            if(!$id || !$name) {
                throw new Exception("Invalid parameters for update");
            }

            // Log incoming data
            error_log("Update Calendar Data: " . json_encode([
                'id' => $id,
                'name' => $name,
                'mainColor' => $mainColor,
                'secondaryColor' => $secondaryColor
            ]));

            // First check if the calendar exists
            $checkStmt = $pdo->prepare("SELECT id FROM calendars WHERE id = ?");
            $checkStmt->execute([$id]);
            if (!$checkStmt->fetch()) {
                throw new Exception("Calendar not found");
            }

            // Prepare SQL with proper column handling
            $sql = "UPDATE calendars SET 
                name = :name,
                mainColor = COALESCE(:mainColor, mainColor),
                secondaryColor = COALESCE(:secondaryColor, secondaryColor),
                mainFontColor = COALESCE(:mainFontColor, mainFontColor),
                secondaryFontColor = COALESCE(:secondaryFontColor, secondaryFontColor)
                WHERE id = :id";

            $stmt = $pdo->prepare($sql);
            
            // Bind parameters
            $params = [
                ':id' => $id,
                ':name' => $name,
                ':mainColor' => $mainColor,
                ':secondaryColor' => $secondaryColor,
                ':mainFontColor' => $mainFontColor,
                ':secondaryFontColor' => $secondaryFontColor
            ];
            
            // Execute update
            $success = $stmt->execute($params);
            
            if (!$success) {
                throw new Exception("Failed to update calendar");
            }

            // Log success
            error_log("Calendar updated successfully. Rows affected: " . $stmt->rowCount());

            // Fetch the full updated record so the client has the latest data
            $selectStmt = $pdo->prepare("SELECT * FROM calendars WHERE id = ?");
            $selectStmt->execute([$id]);
            $updatedCalendar = $selectStmt->fetch(PDO::FETCH_ASSOC);

            if (!$updatedCalendar) {
                throw new Exception("Failed to retrieve updated calendar");
            }

            // Return success response with the full calendar data
            echo json_encode([
                "success" => true,
                "calendar" => $updatedCalendar,
                "message" => "Calendar updated successfully"
            ]);

        } catch (Exception $e) {
            // Log the error
            error_log("Calendar Update Error: " . $e->getMessage());
            
            // Return error response
            http_response_code(500);
            echo json_encode([
                "success" => false,
                "error" => $e->getMessage(),
                "debug" => error_get_last()
            ]);
        }
        break;

    case 'delete_calendar':
        $id = $_POST['id'] ?? 0;
        if(!$id){
            http_response_code(400);
            echo json_encode(["error" => "Calendar id is required"]);
            exit;
        }
        $stmt = $pdo->prepare("DELETE FROM calendars WHERE id = ?");
        $stmt->execute([$id]);
        // Also delete all events for this calendar.
        $stmt = $pdo->prepare("DELETE FROM events WHERE calendar_id = ?");
        $stmt->execute([$id]);
        echo json_encode(["success" => true]);
        break;

    case 'get_events':
        $calendar_id = $_GET['calendar_id'] ?? 0;
        if($calendar_id){
            $stmt = $pdo->prepare("SELECT * FROM events WHERE calendar_id = ?");
            $stmt->execute([$calendar_id]);
        } else {
            $stmt = $pdo->prepare("SELECT * FROM events");
            $stmt->execute();
        }
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($events);
        break;

    case 'create_event':
        $calendar_id = $_POST['calendar_id'] ?? 0;
        $title = $_POST['title'] ?? '';
        $date = $_POST['date'] ?? '';
        $start_time = $_POST['start_time'] ?? '';
        $end_time = $_POST['end_time'] ?? '';
        $description = $_POST['description'] ?? '';
        $eventType = $_POST['eventType'] ?? 'virtual';
        $meetingLink = $_POST['meetingLink'] ?? '';
        $address = $_POST['address'] ?? '';
        $replayLink = $_POST['replayLink'] ?? '';
        $access_type = $_POST['access_type'] ?? 'immediately';
        $access_before = $_POST['access_before'] ?? 60;

        if(!$calendar_id || !$title || !$date || !$start_time || !$end_time){
            http_response_code(400);
            echo json_encode(["error" => "Missing required event fields."]);
            exit;
        }
        $stmt = $pdo->prepare("INSERT INTO events (
            calendar_id, title, date, start_time, end_time, description, 
            eventType, meetingLink, address, replayLink,
            access_type, access_before
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $calendar_id, $title, $date, $start_time, $end_time, $description, 
            $eventType, $meetingLink, $address, $replayLink,
            $access_type, $access_before
        ]);
        echo json_encode(["success" => true, "id" => $pdo->lastInsertId()]);
        break;

    case 'update_event':
        try {
            $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
            $calendar_id = isset($_POST['calendar_id']) ? intval($_POST['calendar_id']) : 0;
            $title = $_POST['title'] ?? '';
            $date = $_POST['date'] ?? '';
            $start_time = $_POST['start_time'] ?? '';
            $end_time = $_POST['end_time'] ?? '';
            $description = $_POST['description'] ?? '';
            $eventType = $_POST['eventType'] ?? 'virtual';
            $meetingLink = $_POST['meetingLink'] ?? '';
            $address = $_POST['address'] ?? '';
            $replayLink = $_POST['replayLink'] ?? '';
            $access_type = $_POST['access_type'] ?? 'immediately';
            $access_before = isset($_POST['access_before']) ? intval($_POST['access_before']) : 60;

            if (!$id || !$calendar_id || empty($title) || empty($date) || empty($start_time) || empty($end_time)) {
                throw new Exception("Missing required fields");
            }

            // First check if the event exists
            $checkStmt = $pdo->prepare("SELECT id FROM events WHERE id = ?");
            $checkStmt->execute([$id]);
            if (!$checkStmt->fetch()) {
                throw new Exception("No event found with ID: $id");
            }

            $sql = "UPDATE events SET 
                calendar_id = ?,
                title = ?,
                date = ?,
                start_time = ?,
                end_time = ?,
                description = ?,
                eventType = ?,
                meetingLink = ?,
                address = ?,
                replayLink = ?,
                access_type = ?,
                access_before = ?
                WHERE id = ?";

            $stmt = $pdo->prepare($sql);
            $result = $stmt->execute([
                $calendar_id,
                $title,
                $date,
                $start_time,
                $end_time,
                $description,
                $eventType,
                $meetingLink,
                $address,
                $replayLink,
                $access_type,
                $access_before,
                $id
            ]);

            if (!$result) {
                throw new Exception("Database update failed: " . implode(" ", $stmt->errorInfo()));
            }

            echo json_encode([
                "success" => true,
                "message" => "Event updated successfully",
                "event" => [
                    "id" => $id,
                    "calendar_id" => $calendar_id,
                    "title" => $title,
                    "date" => $date,
                    "start_time" => $start_time,
                    "end_time" => $end_time,
                    "description" => $description,
                    "eventType" => $eventType,
                    "meetingLink" => $meetingLink,
                    "address" => $address,
                    "replayLink" => $replayLink,
                    "access_type" => $access_type,
                    "access_before" => $access_before
                ]
            ]);

        } catch (Exception $e) {
            error_log("Update Event Error: " . $e->getMessage());
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "error" => $e->getMessage()
            ]);
        }
        break;

    case 'delete_event':
        $id = $_POST['id'] ?? 0;
        if(!$id){
            http_response_code(400);
            echo json_encode(["error" => "Event id is required"]);
            exit;
        }
        $stmt = $pdo->prepare("DELETE FROM events WHERE id=?");
        $stmt->execute([$id]);
        echo json_encode(["success" => true]);
        break;

    case 'clear_events':
        $calendar_id = $_POST['calendar_id'] ?? 0;
        if(!$calendar_id){
            http_response_code(400);
            echo json_encode(["error" => "Calendar id required"]);
            exit;
        }
        $stmt = $pdo->prepare("DELETE FROM events WHERE calendar_id=?");
        $stmt->execute([$calendar_id]);
        echo json_encode(["success" => true]);
        break;

    default:
        http_response_code(400);
        echo json_encode(["error" => "Invalid action"]);
        break;
}
?> 