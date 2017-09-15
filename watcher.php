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

abstract class Timing {
    const FIVE_MINUTES = 300;
    const TEN_MINUTES = 600;
    const ONE_HOUR = 3600;
}

class NotificationException extends Exception {}
class NodeAvailabilityException extends Exception {}

$running = true;

function logme ($var) {
    echo date('r') . ":\r\n";
    echo is_string($var) ? $var : print_r($var, true);
    echo "\r\n";
}

function get_node_data () {
    $response = file_get_contents(NODE_API);
    if ($response === false) {
        throw new \Exception("Cannot access Node API");
    }
    return json_decode($response);
}

function send_notification ($to, $message, $valid = Timing::TEN_MINUTES) {
    try {
        $client = new Client(TWILIO_SID, TWILIO_TOKEN);

        $client->messages->create(
            $to,
            array(
                'from' => FROM,
                'body' => $message,
                'validityPeriod' => $valid
            )
        );
    } catch (\Exception $exception) {
        throw new NotificationException('Error sending notification', 0, $exception);
    }
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
    } else if ($last_result || time() - $last_failures->$key > Timing::ONE_HOUR) {
        $last_failures->$key = time();
        $message .= "{$name} is {$log} (" . (int)$value . "{$units})\r\n";
    }
    $last_results->$key = $result;
    return $result;
}

function run () {
    global $running;

    $nodeUnavailabilityCount = 0;

    $last_results = (object) array(
        CO2 => true,
        PM25 => true,
        TEMPERATURE => true,
        HUMIDITY => true,
    );

    $last_failures = (object) array_map(function () { return 0; }, (array) $last_results);

    while (true) {
        $message = '';
        $data = null;

        try {
            $data = get_node_data();
            $nodeUnavailabilityCount = 0;
        } catch (\Exception $exception) {
            $singleSleep = Timing::FIVE_MINUTES;
            $maxAttempts = 48;
            $nodeUnavailabilityCount++;
            if ($nodeUnavailabilityCount > $maxAttempts) {
                throw new NodeAvailabilityException(
                    'Node unavailable more than ' . $singleSleep * $maxAttempts / Timing::ONE_HOUR  . ' hours', 0, $exception);
            }
            logme($exception->getMessage());
            sleep($singleSleep);
            continue;
        }

        if (time() - strtotime($data->current->ts) > 3 * Timing::FIVE_MINUTES) {
            sleep(Timing::FIVE_MINUTES);
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
            logme($message);
        }

        if (!$running) {
            send_notification(ARTEM, 'Service is back online');
            logme('Back online');
            $running = true;
        }
        sleep(Timing::TEN_MINUTES);
    }
}

function start () {
    global $running;

    try {
        run();
    }
    catch (NotificationException $notificationException) {
        logme($notificationException->getMessage());
    }
    catch (NodeAvailabilityException $nodeAvailabilityException) {
        logme($nodeAvailabilityException->getMessage());
    }
    catch (\Exception $exception) {
        logme('Something went wrong ' . print_r($exception, true));

        try {
            if ($running) {
                send_notification(ARTEM, substr($exception->getMessage(), 0, 160));
            }
        } catch (\Exception $e) {
            logme('Cannot send notification');
        }
    }

    $running = false;
    sleep(Timing::ONE_HOUR);
    logme('Restarting');
    return start();
}

start();
