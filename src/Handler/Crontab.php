<?php

namespace Horseloft\Phalanx\Handler;

use Horseloft\Phalanx\Builder\Request;

class Crontab
{
    /**
     * @var int
     */
    public $enableTime = 3;

    /**
     * 创建进程
     *
     * @param string $name
     * @param callable $callback
     * @param array $args
     */
    public function createProcess(string $name, callable $callback, array $args)
    {
        $pid = pcntl_fork();
        if ($pid == -1) {
            Logger::error('crontab [' . $name . '] 进程创建失败');
        } else if ($pid === 0) {
            // 子进程
            Container::setRequestParameter($args);
            call_user_func($callback, new Request());
            exit();
        }
    }

    /**
     * 进程检测
     */
    public function processObserver()
    {
        while (true) {
            // 如果有进程任务不结束 将一直停留在这里
            $pid = pcntl_waitpid(0, $status, WNOHANG);
            if ($pid == -1) {
                // 发生错误 或全部子进程结束
                break;
            }
        }
    }

    /**
     * 定时任务是否无效
     *
     * @param array $crontab
     *
     * @return bool
     */
    public function isInvalidCrontab(array $crontab): bool
    {
        return empty($crontab['command']) || !is_string($crontab['command']) || !is_callable($crontab['callback']);
    }

    /**
     * 未到定时任务的执行时间
     *
     * 每分钟的前三秒执行一次
     *
     * @param array $runTime
     *
     * @return bool
     */
    public function isInvalidRunTime(array $runTime): bool
    {
        return !in_array(date('i'), $runTime[0]) || !in_array(date('G'), $runTime[1])
            || !in_array(date('j'), $runTime[2]) || !in_array(date('n'), $runTime[3])
            || !in_array(date('W'), $runTime[4]) || date('s') > $this->enableTime;
    }

    /**
     * crontab时间格式化为数组格式
     *
     * @param string $crontabCommand
     * @return array
     */
    public function commandResolve(string $crontabCommand): array
    {
        $commandList = explode(' ', $crontabCommand);
        if (count($commandList) != 5) {
            return [];
        }
        $commandRun = [];
        foreach ($commandList as $index => $command) {
            if ($command == '*') {
                array_push($commandRun, $this->crontabTimeAll($index));
                continue;
            }

            // 斜线分割的时间 斜线右侧应是数字
            if (strpos($command, '*/') === 0) {
                array_push($commandRun, $this->crontabTimeSlant($index, $command));
                continue;
            }

            // 斜线分割的时间 斜线右侧应是符号* 或者 数字
            if (strpos($command, '/') !== false) {
                $commandExp = explode('/', $command);
                $masterCommand = $this->crontabTimeExplode($index, $commandExp[0]);
                $timeSpace = $this->crontabTimeExplode($index, $commandExp[1]);
                if (!is_array($masterCommand) || !is_int($timeSpace)) {
                    continue;
                }
                $runTime = [];
                $inc = 0;
                foreach ($masterCommand as $masterCmd) {
                    $inc++;
                    if ($inc == $timeSpace) {
                        $runTime[] = $masterCmd;
                        $inc = 0;
                    }
                }
                array_push($commandRun, $runTime);
                continue;
            }

            // 逗号分割或短横线分割
            array_push($commandRun, $this->crontabTimeExplode($index, $command));
        }
        $commandRun = array_filter($commandRun);
        if (count($commandRun) != 5) {
            return [];
        }
        return $commandRun;
    }

    /**
     * 短横线-逗号-符号* 分割的时间
     *
     * @param int $index
     * @param string $command
     * @return array|int
     */
    private function crontabTimeExplode(int $index, string $command)
    {
        // 短横线分割
        if (strpos($command, '-') !== false) {
            return $this->crontabTimeQuantum($index, $command);
        }

        // 逗号分割
        if (strpos($command, ',') !== false) {
            return $this->crontabTimeComma($index, $command);
        }

        return $command == '*' ? 1 : intval($command);
    }

    /**
     * 逗号分割的时间
     *
     * @param int $index
     * @param string $string
     * @return array
     */
    private function crontabTimeComma(int $index, string $string): array
    {
        $list = explode(',', $string);
        $max = $this->crontabMaxTime($index);
        $min = $this->crontabMinTime($index);

        $comma = [];
        for($i = $min; $i <= $max; $i++) {
            if (in_array($i, $list)) {
                $comma[] = $i;
            }
        }
        return $comma;
    }

    /**
     * 斜线分割的时间
     *
     * @param int $index
     * @param string $string
     * @return array
     */
    private function crontabTimeSlant(int $index, string $string): array
    {
        $list = explode('/', $string);
        if (count($list) != 2 || $list[0] != '*') {
            return [];
        }
        $space = intval($list[1]);
        $max = $this->crontabMaxTime($index);
        $min = $this->crontabMinTime($index);

        $slant = [];
        for($i = $min; $i <= $max; $i++) {
            if (($i % $space) == 0) {
                $slant[] = $i;
            }
        }
        return $slant;
    }

    /**
     * 短横线分割的时间
     *
     * @param int $index
     * @param string $string
     * @return array
     */
    private function crontabTimeQuantum(int $index, string $string): array
    {
        $list = explode('-', $string);
        if (count($list) != 2) {
            return [];
        }
        $start = intval($list[0]);
        $end = intval($list[1]);
        $max = $this->crontabMaxTime($index);
        $min = $this->crontabMinTime($index);

        $quantum = [];
        for($i = $min; $i <= $max; $i++) {
            if ($i >= $start && $i <= $end) {
                $quantum[] = $i;
            }
        }
        return $quantum;
    }

    /**
     * 全部时间段
     *
     * @param int $index
     * @return array
     */
    private function crontabTimeAll(int $index): array
    {
        $max = $this->crontabMaxTime($index);
        $min = $this->crontabMinTime($index);

        $all = [];
        for($j = $min; $j <= $max; $j++) {
            $all[] = $j;
        }
        return $all;
    }

    /**
     * 定时任务的时间最大值
     *
     * @param int $index
     * @return int
     */
    private function crontabMaxTime(int $index): int
    {
        switch($index) {
            case 0:
                // 分 0 - 59
                $flag = 60;
                break;
            case 1:
                // 时 0 - 23
                $flag = 23;
                break;
            case 2:
                // 日 1 - 28 / 31
                $flag = 31;
                break;
            case 3:
                // 月 1 - 12
                $flag = 12;
                break;
            default:
                // 周 1 - 53
                $flag = 53;
                break;
        }
        return $flag;
    }

    /**
     * 定时任务的时间最小值
     *
     * @param int $index
     * @return int
     */
    private function crontabMinTime(int $index): int
    {
        if ($index < 2) {
            return 0;
        }
        return 1;
    }
}
