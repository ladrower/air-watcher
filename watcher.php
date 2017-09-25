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

function logMe ($var) {
    echo date('r') . ":\r\n";
    echo is_string($var) ? $var : print_r($var, true);
    echo "\r\n";
}

function interpolateLinear ($value, $fromRange, $toRange) {
    $percent = ($value - $fromRange[0]) / ($fromRange[1] - $fromRange[0]);
    return $toRange[0] + ($toRange[1] - $toRange[0]) * $percent;
}

function pm25ToAqi ($value) {
    if ($value <= 12) {
        return (int) interpolateLinear ($value, [0,12], [0,50]);
    } else if ($value <= 35.4) {
        return (int) interpolateLinear ($value, [12.1,35.4], [51,100]);
    } else if ($value <= 55.4) {
        return (int)interpolateLinear ($value, [35.5,55.4], [101,150]);
    } else if ($value <= 150.4) {
        return (int) interpolateLinear ($value, [55.4,150.4], [151,200]);
    } else if ($value <= 250.4) {
        return (int) interpolateLinear ($value, [150.5,250.4], [201,300]);
    } else if ($value <= 350.4) {
        return (int) interpolateLinear ($value, [250.5,350.4], [301,400]);
    } else if ($value <= 500) {
        return (int) interpolateLinear ($value, [350.5,500], [401,500]);
    }

    throw new \Exception("Value out of range");
}

function get_node_data () {
    $response = file_get_contents(NODE_API);
    if ($response === false) {
        throw new \Exception("Cannot access Node API");
    }
    $data = json_decode($response);
    if (!is_object($data) || !isset($data->current)) {
        throw new \Exception("Invalid Node data");
    }
    
    return $data->current;
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

function analyze (
        $data,
        $key,
        $last_results,
        $last_failures,
        $lower_limit,
        $upper_limit,
        $lower_offset,
        $upper_offset,
        $name,
        $formatter
) {
    $value = $data->$key;
    $last_result = $last_results->$key;
    $level = '';
    $message = '';
    $restoring = false;

    if ($last_result) {
        $lower_offset = 0;
        $upper_offset = 0;
    }

    $result = false;
    if ($value < $lower_limit + $lower_offset) {
        $level = 'LOW';
        $restoring = $value > $lower_limit;
    } else if ($value > $upper_limit - $upper_offset) {
        $level = 'HIGH';
        $restoring = $value < $upper_limit;
    } else {
        $result = true;
    }


    if ($result) {
        if (!$last_result) {
            $message .= "{$name} is OK";
        }
    } else if ($last_result) {
        $last_failures->$key = time();
        $message .= "{$name} is TOO {$level}";
    } else if (time() - $last_failures->$key > 4 * Timing::ONE_HOUR) {
        $last_failures->$key = time();
        $message .= "{$name} is still " . ($restoring ? 'QUITE' : 'TOO')  . " {$level}";
    }

    if ($message !== '') {
        $message .= ' (' . $formatter($value) . ")\r\n";
    }

    $last_results->$key = $result;
    return $message;
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
            logMe($exception->getMessage());
            sleep($singleSleep);
            continue;
        }

        if (time() - strtotime($data->ts) > 3 * Timing::FIVE_MINUTES) {
            sleep(Timing::FIVE_MINUTES);
            continue;
        }
        
        $message .= analyze($data, PM25, $last_results, $last_failures, 0, 35.5, 0, 23.4, 'PM2.5', function ($v) {
            return pm25ToAqi($v) . ' | '. round($v, 1) . 'µg/m3';
        });
        $message .= analyze($data, CO2, $last_results, $last_failures, 0, 1200, 0, 400, 'CO2', function ($v) {
            return (int) $v . 'ppm';
        });
        $message .= analyze($data, HUMIDITY, $last_results, $last_failures, 30, 70, 10, 10, 'Humidity', function ($v) {
            return (int) $v . '%';
        });
        $message .= analyze($data, TEMPERATURE, $last_results, $last_failures, 16, 26, 2, 2, 'Temperature', function ($v) {
            return (int) $v . '°';
        });

        if ($message !== '') {
            $dt = new DateTime($data->ts);
            $dt->setTimezone(new DateTimeZone(TIMEZONE));
            $message = $dt->format('d M H:i') . "\r\n" . $message;
            send_notification(ARTEM, $message);
            send_notification(ALINA, $message);
            logMe($message);
        }

        if (!$running) {
            send_notification(ARTEM, 'Service is back online');
            logMe('Back online');
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
        logMe($notificationException->getMessage());
    }
    catch (NodeAvailabilityException $nodeAvailabilityException) {
        logMe($nodeAvailabilityException->getMessage());
    }
    catch (\Exception $exception) {
        logMe('Something went wrong ' . print_r($exception, true));

        try {
            if ($running) {
                send_notification(ARTEM, substr($exception->getMessage(), 0, 160));
            }
        } catch (\Exception $e) {
            logMe('Cannot send notification');
        }
    }

    $running = false;
    sleep(Timing::ONE_HOUR);
    logMe('Restarting');
    return start();
}

start();
