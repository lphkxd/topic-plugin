<?php
/**
 * Created by PhpStorm.
 * User: 白猫
 * Date: 2019/5/22
 * Time: 11:17
 */

namespace ESD\Plugins\Topic;


use Ds\Set;
use ESD\BaseServer\Memory\CrossProcess\Table;
use ESD\Plugins\EasyRoute\GetBoostSend;
use ESD\Plugins\Uid\GetUid;

class Topic
{
    use GetBoostSend;
    use GetUid;
    /**
     * @var Table
     */
    private $topicTable;
    protected $subArr = [];

    public function __construct(Table $topicTable)
    {
        //先读table，因为进程有可能会重启
        $this->topicTable = $topicTable;
        foreach ($this->topicTable as $value) {
            $this->addSubFormTable($value['topic'], $value['uid']);
        }
    }

    /**
     * @param $topic
     * @param $uid
     */
    private function addSubFormTable($topic, $uid)
    {
        if (empty($uid)) return;
        if (!isset($this->subArr[$topic])) {
            $this->subArr[$topic] = new Set();
        }
        $this->subArr[$topic]->add($uid);
    }

    /**
     * @param $topic
     * @param $uid
     * @return bool
     */
    public function hasTopic($topic, $uid)
    {
        $set = $this->subArr[$topic] ?? null;
        if ($set == null) return false;
        return $set->contains($uid);
    }

    /**
     * 添加订阅
     * @param $topic
     * @param $uid
     */
    public function addSub($topic, $uid)
    {
        $this->addSubFormTable($topic, $uid);
        $this->topicTable->set($topic . $uid, ["topic" => $topic, "uid" => $uid]);
    }

    /**
     * 移除订阅
     * @param $topic
     * @param $uid
     */
    public function removeSub($topic, $uid)
    {
        if (empty($uid)) return;
        if (isset($this->subArr[$topic])) {
            $this->subArr[$topic]->remove($uid);
            if ($this->subArr[$topic]->count() == 0) {
                unset($this->subArr[$topic]);
            }
        }
        $this->topicTable->del($topic . $uid);
    }

    /**
     * 清除Uid的订阅
     * @param $uid
     */
    public function clearUidSub($uid)
    {
        if (empty($uid)) return;
        foreach ($this->subArr as $topic => $sub) {
            $sub->remove($uid);
            $this->topicTable->del($topic . $uid);
        }
    }


    /**
     * 构建订阅树,只允许5层
     * @param $topic
     * @return Set
     */
    private function buildTrees($topic)
    {
        $isSYS = false;
        if ($topic[0] == "$") {
            $isSYS = true;
        }
        $p = explode("/", $topic);
        $countPlies = count($p);
        $result = new Set();
        if (!$isSYS) {
            $result->add("#");
        }
        for ($j = 0; $j < $countPlies; $j++) {
            $a = array_slice($p, 0, $j + 1);
            $arr = [$a];
            $count_a = count($a);
            $value = implode('/', $a);
            $result->add($value . "/#");
            $complete = false;
            if ($count_a == $countPlies) {
                $complete = true;
                $result->add($value);
            }
            for ($i = 0; $i < $count_a; $i++) {
                $temp = [];
                foreach ($arr as $one) {
                    $this->help_replace_plus($one, $temp, $result, $complete, $isSYS);
                }
                $arr = $temp;
            }
        }
        return $result;
    }

    private function help_replace_plus($arr, &$temp, &$result, $complete, $isSYS)
    {
        $count = count($arr);
        $m = 0;
        if ($isSYS) $m = 1;
        for ($i = $m; $i < $count; $i++) {
            $new = $arr;
            if ($new[$i] == '+') continue;
            $new[$i] = '+';
            $temp[] = $new;
            $value = implode('/', $new);
            $result->add($value . "/#");
            if ($complete) {
                $result->add($value);
            }
        }
    }


    /**
     * @param $topic
     * @param $data
     * @param array $excludeUidList
     */
    public function pub($topic, $data, $excludeUidList = [])
    {
        $tree = $this->buildTrees($topic);
        foreach ($tree as $one) {
            if (isset($this->subArr[$one])) {
                foreach ($this->subArr[$one] as $uid) {
                    if (!in_array($uid, $excludeUidList)) {
                        $this->pubToUid($uid, $data, $topic);
                    }
                }
            }
        }
    }

    /**
     * @param $uid
     * @param $data
     * @param $topic
     */
    private function pubToUid($uid, $data, $topic)
    {
        $fd = $this->getUidFd($uid);
        $this->autoBoostSend($fd, $data, $topic);
    }
}