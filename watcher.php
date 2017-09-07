<?php
// run the script from terminal:
// nohup php watcher.php > output.log &

// see the process id to kill it:
// ps -ef

require __DIR__ . '/config.php';

require __DIR__ . '/twilio-php-master/Twilio/autoload.php';

use Twilio\Rest\Client;

define('CO2', 'co');
define('PM25', 'p2');
define('TEMPERATURE', 'tp');
define('HUMIDITY', 'hm');

$running = true;

function get_node_data () {
    return json_decode(file_get_contents(NODE_API));
}

function send_notification ($to, $message) {
    $sid = TWILIO_SID;
    $token = TWILIO_TOKEN;
    $client = new Client($sid, $token);

    $client->messages->create(
        $to,
        array(
            'from' => FROM,
            'body' => $message
        )
    );
}

function analyze ($data, $key, $last_results, $last_failures, $lower_limit, $upper_limit, $lower_offset, $upper_offset, $name, $units, &$message) {
    $value = $data->current->$key;
    $last_result = $last_results->$key;
    $log = '';

    if ($last_result) {
        $lower_offset = 0;
        $upper_offset = 0;
    }

    $result = false;
    if ($value < $lower_limit + $lower_offset) {
        $log = 'TOO LOW';
    } else if ($value > $upper_limit - $upper_offset) {
        $log = 'TOO HIGH';
    } else {
        $result = true;
    }
    if ($result) {
        if (!$last_result) {
            $message .= "{$name} is OK (" . (int)$value . "{$units})\r\n";
        }
    } else if ($last_result || time() - $last_failures->$key > 3600) {
        $last_failures->$key = time();
        $message .= "{$name} is {$log} (" . (int)$value . "{$units})\r\n";
    }
    $last_results->$key = $result;
    return $result;
}

function run () {
    global $running;

    $last_results = (object) array(
        CO2 => true,
        PM25 => true,
        TEMPERATURE => true,
        HUMIDITY => true,
    );

    $last_failures = (object) array_map(function () { return 0; }, (array) $last_results);

    while (true) {
        $message = '';
        $data = get_node_data();

        if (time() - strtotime($data->current->ts) > 60 * 15) {
            sleep(300);
            continue;
        }

        analyze($data, PM25, $last_results, $last_failures, 0, 30, 0, 15, 'PM2.5', 'µg/m3', $message);
        analyze($data, CO2, $last_results, $last_failures, 0, 1000, 0, 300, 'CO2', 'ppm', $message);
        analyze($data, HUMIDITY, $last_results, $last_failures, 30, 70, 10, 10, 'Humidity', '%', $message);
        analyze($data, TEMPERATURE, $last_results, $last_failures, 15, 25, 3, 1, 'Temperature', '°', $message);

        if ($message !== '') {
            $dt = new DateTime($data->current->ts);
            $dt->setTimezone(new DateTimeZone(TIMEZONE));
            $message = $dt->format('d M H:i') . "\r\n" . $message;
            send_notification(ARTEM, $message);
            send_notification(ALINA, $message);
        }

        if (!$running) {
            send_notification(ARTEM, "Service is back online");
            echo "Back online" . date('r') . ".\r\n";
            $running = true;
        }
        sleep(600);
    }
}

function start () {
    global $running;
    try {
        run();
    } catch (\Exception $exception) {
        echo "Script failed " . date('r') . ".\r\n";
        var_dump($exception);
        sleep(300);
        try {
            if ($running) {
                send_notification(ARTEM, "Script failed");
            }
        } catch (\Exception $e) {
            echo "Cannot send failure notification. Going to sleep for 1 hour.\r\n";
            sleep(3600);
        }
        $running = false;
        echo "Restarting" . date('r') . ".\r\n";
        start();
    }
}

start();
