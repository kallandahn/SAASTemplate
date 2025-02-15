<?php
// Database setup section
require_once 'onboarding/config.php';

// Get calendar ID from URL slug
$calendar_slug = isset($_GET['calendar']) ? $_GET['calendar'] : '';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Fetch calendar details based on name (slug)
    $stmt = $pdo->prepare("SELECT * FROM calendars WHERE name = ?");
    $stmt->execute([$calendar_slug]);
    $calendar = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$calendar) {
        // Return proper JSON error
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Calendar not found']);
        exit;
    }

} catch(PDOException $e) {
    // Return proper JSON error
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// Only continue with HTML output if we have a valid calendar
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($calendar['name']); ?> - Calendar</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --primary-color: <?php echo $calendar['mainColor'] ?? '#27b6c1'; ?>;
            --secondary-color: <?php echo $calendar['secondaryColor'] ?? '#f5de81'; ?>;
            --text-color: #3e3e3e;
            --background-color: #f0f4f8;
            --card-background: #ffffff;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background-color: var(--background-color);
            color: var(--text-color);
            min-height: 100vh;
            padding: 20px;
        }

        .dashboard-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .main-content {
            background: var(--card-background);
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            padding: 30px;
        }

        .calendar-header {
            background: var(--primary-color);
            color: white;
            padding: 26px;
            text-align: center;
            border-radius: 10px 10px 0 0;
            margin: -30px -30px 30px -30px;
        }

        .calendar-header h1 {
            font-size: 28px;
            margin: 0;
            font-weight: 700;
        }

        .calendar-nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 13px 20px;
            background: var(--background-color);
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .nav-btn {
            background: none;
            border: 1px solid var(--text-color);
            color: var(--text-color);
            cursor: pointer;
            font-size: 11px;
            padding: 7px 13px;
            border-radius: 20px;
            transition: all 0.3s;
            font-weight: 700;
            min-width: 40px;
        }

        .nav-btn:hover {
            background: var(--text-color);
            color: var(--card-background);
        }

        #currentMonth {
            font-size: 16px;
            font-weight: 700;
            text-align: center;
            flex-grow: 1;
        }

        .weekdays {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 7px;
            padding: 7px 13px;
            background-color: var(--secondary-color);
            border-radius: 10px;
            margin-bottom: 10px;
        }

        .weekday {
            padding: 7px;
            font-size: 12px;
            font-weight: bold;
            text-align: center;
        }

        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 7px;
            padding: 13px;
            min-width: 300px;
        }

        .calendar-day {
            aspect-ratio: 1;
            min-height: 60px;
            max-height: 120px;
            overflow: auto;
            border: 1px solid #e0e0e0;
            border-radius: 7px;
            padding: 7px;
            font-weight: bold;
            display: flex;
            flex-direction: column;
            transition: all 0.3s;
            position: relative;
            background: var(--card-background);
        }

        .calendar-day:hover {
            background-color: var(--secondary-color);
            transform: scale(1.03);
        }

        .calendar-day-header {
            text-align: right;
            margin-bottom: 3px;
            color: var(--text-color);
            font-size: 12px;
        }

        .calendar-day-event {
            background-color: var(--primary-color);
            color: white;
            padding: 3px 6px;
            border-radius: 3px;
            font-size: 12px;
            margin-bottom: 2px;
            cursor: pointer;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .events-section {
            margin-top: 40px;
            padding: 20px;
            background: var(--background-color);
            border-radius: 10px;
        }

        .events-section h2 {
            color: var(--text-color);
            font-size: 20px;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--secondary-color);
        }

        .events-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
            margin-bottom: 30px;
        }

        .event-card {
            background: var(--card-background);
            border-radius: 10px;
            padding: 15px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s;
        }

        .event-card:hover {
            transform: translateY(-3px);
        }

        .event-date {
            color: var(--primary-color);
            font-weight: bold;
            font-size: 14px;
            margin-bottom: 5px;
        }

        .event-title {
            font-size: 18px;
            margin-bottom: 10px;
            color: var(--text-color);
        }

        .event-description {
            color: #666;
            font-size: 14px;
            line-height: 1.6;
        }

        /* Popup Styles */
        .popup {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            backdrop-filter: blur(5px);
        }

        .popup-content {
            background-color: var(--card-background);
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 0 30px rgba(0, 0, 0, 0.3);
            max-width: 600px;
            width: 90%;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
        }

        .popup-title {
            font-size: 24px;
            color: var(--primary-color);
            margin-bottom: 15px;
        }

        .popup-date {
            font-size: 16px;
            color: var(--text-color);
            margin-bottom: 10px;
        }

        .popup-description {
            font-size: 14px;
            line-height: 1.6;
            color: var(--text-color);
            margin-bottom: 20px;
        }

        .popup-countdown {
            background: var(--primary-color);
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            margin-bottom: 20px;
            display: flex;
            justify-content: center;
            gap: 15px;
        }

        @media (max-width: 768px) {
            .dashboard-container {
                padding: 10px;
            }

            .main-content {
                padding: 15px;
            }

            .calendar-header {
                padding: 20px;
                margin: -15px -15px 20px -15px;
            }

            .calendar-header h1 {
                font-size: 24px;
            }

            .weekday {
                font-size: 11px;
            }

            .calendar-day {
                min-height: 50px;
            }
        }

        .no-events {
            text-align: center;
            padding: 20px;
            color: #666;
            font-style: italic;
        }

        .event-time {
            color: var(--primary-color);
            font-size: 0.9em;
        }

        .event-location {
            margin-top: 10px;
            color: #666;
            font-size: 0.9em;
        }

        .event-location i {
            color: var(--primary-color);
            margin-right: 5px;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <div class="main-content">
            <div class="calendar-header">
                <h1><?php echo htmlspecialchars($calendar['name']); ?></h1>
            </div>
            
            <div class="calendar-nav">
                <button id="prevMonth" class="nav-btn"><i class="fas fa-chevron-left"></i></button>
                <span id="currentMonth"></span>
                <button id="nextMonth" class="nav-btn"><i class="fas fa-chevron-right"></i></button>
            </div>

            <div class="weekdays">
                <div class="weekday">Sun</div>
                <div class="weekday">Mon</div>
                <div class="weekday">Tue</div>
                <div class="weekday">Wed</div>
                <div class="weekday">Thu</div>
                <div class="weekday">Fri</div>
                <div class="weekday">Sat</div>
            </div>

            <div id="calendarGrid" class="calendar-grid"></div>

            <div class="events-section">
                <h2>Upcoming Events</h2>
                <div id="upcomingEvents" class="events-list"></div>
                
                <h2>Past Events</h2>
                <div id="pastEvents" class="events-list"></div>
            </div>
        </div>
    </div>

    <!-- Event Details Popup -->
    <div id="eventDetailsPopup" class="popup">
        <div class="popup-content">
            <div class="popup-title"></div>
            <div class="popup-date"></div>
            <div class="popup-description"></div>
            <div class="popup-countdown"></div>
        </div>
    </div>

    <script>
        class SharedCalendar {
            constructor(calendarData) {
                this.calendar = calendarData;
                this.events = [];
                this.currentDate = new Date();
                this.selectedDate = null;
                this.init();
            }

            async init() {
                await this.loadEvents();
                this.setupEventListeners();
                this.renderCalendar();
                this.renderEvents();
            }

            async loadEvents() {
                try {
                    const response = await fetch(`api.php?action=get_events&calendar_id=${this.calendar.id}`);
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    const data = await response.json();
                    
                    // Handle the direct events array response
                    this.events = Array.isArray(data) ? data : [];
                    
                } catch (error) {
                    console.error('Error loading events:', error);
                    this.events = [];
                }
            }

            setupEventListeners() {
                document.getElementById('prevMonth').addEventListener('click', () => {
                    this.currentDate.setMonth(this.currentDate.getMonth() - 1);
                    this.renderCalendar();
                });

                document.getElementById('nextMonth').addEventListener('click', () => {
                    this.currentDate.setMonth(this.currentDate.getMonth() + 1);
                    this.renderCalendar();
                });

                document.getElementById('eventDetailsPopup').addEventListener('click', (e) => {
                    if (e.target === document.getElementById('eventDetailsPopup')) {
                        this.closeEventDetailsPopup();
                    }
                });
            }

            renderCalendar() {
                const year = this.currentDate.getFullYear();
                const month = this.currentDate.getMonth();
                
                // Update current month display
                const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 
                                  'July', 'August', 'September', 'October', 'November', 'December'];
                document.getElementById('currentMonth').textContent = `${monthNames[month]} ${year}`;

                // Calculate first day of month
                const firstDay = new Date(year, month, 1);
                const lastDay = new Date(year, month + 1, 0);
                const startingDay = firstDay.getDay();
                const totalDays = lastDay.getDate();

                const calendarGrid = document.getElementById('calendarGrid');
                calendarGrid.innerHTML = '';

                // Add empty cells for days before start of month
                for (let i = 0; i < startingDay; i++) {
                    const emptyDay = document.createElement('div');
                    emptyDay.className = 'calendar-day';
                    calendarGrid.appendChild(emptyDay);
                }

                // Add days of the month
                for (let day = 1; day <= totalDays; day++) {
                    const dayElement = document.createElement('div');
                    dayElement.className = 'calendar-day';
                    
                    const dayHeader = document.createElement('div');
                    dayHeader.className = 'calendar-day-header';
                    dayHeader.textContent = day;
                    dayElement.appendChild(dayHeader);

                    // Add events for this day
                    const currentDate = new Date(year, month, day);
                    const dayEvents = this.events.filter(event => {
                        const eventDate = new Date(event.date);
                        return eventDate.getDate() === day && 
                               eventDate.getMonth() === month && 
                               eventDate.getFullYear() === year;
                    });

                    dayEvents.forEach(event => {
                        const eventElement = document.createElement('div');
                        eventElement.className = 'calendar-day-event';
                        eventElement.textContent = event.title;
                        eventElement.addEventListener('click', () => this.showEventDetails(event));
                        dayElement.appendChild(eventElement);
                    });

                    calendarGrid.appendChild(dayElement);
                }
            }

            renderEvents() {
                const upcomingEvents = document.getElementById('upcomingEvents');
                const pastEvents = document.getElementById('pastEvents');
                upcomingEvents.innerHTML = '';
                pastEvents.innerHTML = '';

                const now = new Date();
                const timezone = this.calendar.timezone || 'America/New_York';

                // Create arrays for upcoming, current, and past events
                let upcoming = [];
                let current = [];
                let past = [];

                // Sort events into upcoming, current, and past
                this.events.forEach(event => {
                    const eventStartDateTime = new Date(`${event.date}T${event.start_time || '00:00:00'}`);
                    const eventEndDateTime = event.end_time ? 
                        new Date(`${event.date}T${event.end_time}`) : 
                        new Date(eventStartDateTime.getTime() + 3600000); // Default to 1 hour if no end time

                    if (now < eventStartDateTime) {
                        upcoming.push({ event, startDate: eventStartDateTime, endDate: eventEndDateTime });
                    } else if (now >= eventStartDateTime && now <= eventEndDateTime) {
                        current.push({ event, startDate: eventStartDateTime, endDate: eventEndDateTime });
                    } else {
                        past.push({ event, startDate: eventStartDateTime, endDate: eventEndDateTime });
                    }
                });

                // Sort the arrays
                upcoming.sort((a, b) => a.startDate - b.startDate);
                current.sort((a, b) => a.endDate - b.endDate);
                past.sort((a, b) => b.startDate - a.startDate);

                // Render events
                if (upcoming.length === 0 && current.length === 0) {
                    upcomingEvents.innerHTML = '<div class="no-events">No upcoming events</div>';
                } else {
                    // Render current events first
                    current.forEach(({ event }) => {
                        upcomingEvents.appendChild(this.createEventCard(event, true));
                    });
                    // Then render upcoming events
                    upcoming.forEach(({ event }) => {
                        upcomingEvents.appendChild(this.createEventCard(event, false));
                    });
                }

                // Render past events
                if (past.length === 0) {
                    pastEvents.innerHTML = '<div class="no-events">No past events</div>';
                } else {
                    past.forEach(({ event }) => {
                        pastEvents.appendChild(this.createEventCard(event, false));
                    });
                }
            }

            createEventCard(event, isCurrentEvent = false) {
                const card = document.createElement('div');
                card.className = 'event-card';
                
                const eventDate = new Date(event.date + 'T' + (event.start_time || '00:00:00'));
                
                const formattedDate = eventDate.toLocaleDateString('en-US', {
                    weekday: 'long',
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric'
                });

                const formattedTime = event.start_time ? 
                    new Date(`2000-01-01T${event.start_time}`).toLocaleTimeString('en-US', {
                        hour: 'numeric',
                        minute: '2-digit',
                        hour12: true
                    }) : '';

                card.innerHTML = `
                    <div class="event-date">
                        ${formattedDate}
                        ${formattedTime ? `<span class="event-time"> at ${formattedTime}</span>` : ''}
                    </div>
                    <div class="event-title">${event.title}</div>
                    ${event.description ? `<div class="event-description">${event.description}</div>` : ''}
                    ${this.getLocationHtml(event, isCurrentEvent)}
                `;

                card.addEventListener('click', () => this.showEventDetails(event));
                return card;
            }

            getLocationHtml(event, isCurrentEvent) {
                if (event.eventType === 'virtual' && event.meetingLink) {
                    const showLink = this.shouldShowEventAccess(event);
                    return showLink ? `
                        <div class="event-location ${isCurrentEvent ? 'happening-now' : ''}">
                            <i class="fas fa-video"></i> Virtual Event
                            <a href="${event.meetingLink}" target="_blank" class="join-meeting-btn">
                                ${isCurrentEvent ? 'Join Now' : 'View Meeting Link'}
                            </a>
                        </div>
                    ` : `
                        <div class="event-location">
                            <i class="fas fa-video"></i> Virtual Event
                            <span>(Link will be available ${this.getAccessTimeText(event)})</span>
                        </div>
                    `;
                } else if (event.eventType === 'physical' && event.address) {
                    return `
                        <div class="event-location">
                            <i class="fas fa-map-marker-alt"></i> ${event.address}
                            <a href="https://maps.google.com?q=${encodeURIComponent(event.address)}" 
                               target="_blank" class="directions-btn">Get Directions</a>
                        </div>
                    `;
                }
                return '';
            }

            shouldShowEventAccess(event) {
                if (event.access_type === 'immediately') return true;
                
                const eventDate = new Date(`${event.date}T${event.start_time}`);
                const now = new Date();
                const accessTime = new Date(eventDate.getTime() - (event.access_before * 60 * 1000));
                
                return now >= accessTime;
            }

            getAccessTimeText(event) {
                if (event.access_type === 'immediately') return 'immediately';
                return `${event.access_before} minutes before the event`;
            }

            showEventDetails(event) {
                const popup = document.getElementById('eventDetailsPopup');
                const content = popup.querySelector('.popup-content');
                
                content.querySelector('.popup-title').textContent = event.title;
                
                const eventDate = new Date(event.date);
                const formattedDate = eventDate.toLocaleDateString('en-US', {
                    weekday: 'long',
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric'
                });
                
                content.querySelector('.popup-date').textContent = formattedDate;
                content.querySelector('.popup-description').textContent = event.description || 'No description available';
                
                // Start countdown if event is in the future
                const now = new Date();
                if (eventDate > now) {
                    this.startCountdown(eventDate, event.start_time, content.querySelector('.popup-countdown'));
                } else {
                    content.querySelector('.popup-countdown').textContent = 'Event has passed';
                }

                popup.style.display = 'block';
            }

            startCountdown(eventDate, eventTime, countdownElement) {
                if (this.countdownInterval) {
                    clearInterval(this.countdownInterval);
                }

                const updateCountdown = () => {
                    const now = new Date();
                    const eventDateTime = new Date(eventDate);
                    
                    if (eventTime) {
                        const [hours, minutes] = eventTime.split(':');
                        eventDateTime.setHours(parseInt(hours), parseInt(minutes), 0);
                    }

                    const difference = eventDateTime - now;

                    if (difference > 0) {
                        const days = Math.floor(difference / (1000 * 60 * 60 * 24));
                        const hours = Math.floor((difference % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                        const minutes = Math.floor((difference % (1000 * 60 * 60)) / (1000 * 60));
                        const seconds = Math.floor((difference % (1000 * 60)) / 1000);

                        countdownElement.innerHTML = `
                            <div>
                                <div>${days}</div>
                                <div>Days</div>
                            </div>
                            <div>
                                <div>${hours}</div>
                                <div>Hours</div>
                            </div>
                            <div>
                                <div>${minutes}</div>
                                <div>Minutes</div>
                            </div>
                            <div>
                                <div>${seconds}</div>
                                <div>Seconds</div>
                            </div>
                        `;
                    } else {
                        countdownElement.textContent = 'Event has passed';
                        clearInterval(this.countdownInterval);
                    }
                };

                updateCountdown();
                this.countdownInterval = setInterval(updateCountdown, 1000);
            }

            closeEventDetailsPopup() {
                const popup = document.getElementById('eventDetailsPopup');
                popup.style.display = 'none';
                if (this.countdownInterval) {
                    clearInterval(this.countdownInterval);
                }
            }
        }

        // Initialize the shared calendar
        const calendarData = <?php echo json_encode($calendar); ?>;
        const sharedCalendar = new SharedCalendar(calendarData);
    </script>
</body>
</html> 