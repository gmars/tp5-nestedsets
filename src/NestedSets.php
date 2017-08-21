<?php
/**
 * Created by PhpStorm.
 * Author: 魏永强   <hayixia606@163.com>
 * GitHub: https://github.com/gmars
 * Blog: http://blog.csdn.net/marswill
 * Date: 2017/8/21
 * Time: 下午7:34
 */

namespace gmars\nestedsets;


use think\Db;
use think\Exception;

class NestedSets
{
    /**
     * @var 表名
     */
    private $tableName;

    /**
     * @var string 左键
     */
    private $leftKey = "left_key";

    /**
     * @var string 右键
     */
    private $rightKey = "right_key";

    /**
     * @var string 父亲字段
     */
    private $parentKey = "parent_id";

    /**
     * @var string 节点深度
     */
    private $levelKey = "level";

    /**
     * @var string 主键
     */
    private $primaryKey = "id";

    /**
     * @var string 多根节点的根节点字段名
     */
    private $multi = "root_id";

    /**
     * @var array 节点的缓存
     */
    private static $itemCache = [];

    /**
     * NestedSets constructor.
     * @param $dbTarg mixed 数据表名或者模型对象
     * @param null $leftKey
     * @param null $rightKey
     * @param null $parentKey
     * @param null $levelKey
     * @param null $primaryKey
     * @throws Exception
     */
    public function __construct($dbTarg, $leftKey = null, $rightKey = null, $parentKey = null, $levelKey = null, $primaryKey = null)
    {
        //如果是表名则处理配置
        if (is_string($dbTarg)) {
            $this->tableName = $dbTarg;
        }

        //允许传入模型对象
        if (is_object($dbTarg)) {
            if (method_exists($dbTarg, 'getTable')) {
                throw new Exception('不能传入该对象');
            }

            $this->tableName = $dbTarg->getTable();
            if (property_exists($dbTarg, 'nestedConfig') && is_array($dbTarg->nestedConfig)) {
                isset($dbTarg->nestedConfig['leftKey']) && $this->leftKey = $dbTarg->nestedConfig['leftKey'];
                isset($dbTarg->nestedConfig['rightKey']) && $this->rightKey = $dbTarg->nestedConfig['rightKey'];
                isset($dbTarg->nestedConfig['parentKey']) && $this->parentKey = $dbTarg->nestedConfig['parentKey'];
                isset($dbTarg->nestedConfig['primaryKey']) && $this->primaryKey = $dbTarg->nestedConfig['primaryKey'];
                isset($dbTarg->nestedConfig['levelKey']) && $this->levelKey = $dbTarg->nestedConfig['levelKey'];
            }
        }

        //构造方法中传入的配置会覆盖其他方式的配置
        isset($leftKey) && $this->leftKey = $leftKey;
        isset($rightKey) && $this->rightKey = $rightKey;
        isset($parentKey) && $this->parentKey = $parentKey;
        isset($primaryKey) && $this->primaryKey = $primaryKey;
        isset($levelKey) && $this->levelKey = $levelKey;
    }

    /**
     * @return false|\PDOStatement|string|\think\Collection
     * 获取整棵树
     */
    public function getTree()
    {
        return Db::table($this->tableName)->order("{$this->leftKey}")->select();
    }

    /**
     * @param $id
     * @return false|\PDOStatement|string|\think\Collection
     * @throws Exception
     * 获取当前节点的所有分支节点|不包含当前节点
     */
    public function getBranch($id, $optionOne = '>', $optionTwo = '<')
    {
        $item = $this->getItem($id);
        if (!$item) {
            throw new Exception('没有该节点');
        }

        $condition[$this->leftKey] = [$optionOne, $item[$this->leftKey]];
        $condition[$this->rightKey] = [$optionTwo, $item[$this->rightKey]];
        return Db::table($this->tableName)
            ->where($condition)
            ->order("{$this->leftKey}")
            ->select();
    }

    /**
     * @param $id
     * @return false|\PDOStatement|string|\think\Collection
     * 获取当前节点的所有分支节点 | 包含当前节点
     */
    public function getPath($id)
    {
        return $this->getBranch($id, ">=", "<=");
    }

    /**
     * @param $id
     * @return false|\PDOStatement|string|\think\Collection
     * 获取该节点的所有子节点 | 注意是子节点，不包含孙节点等
     */
    public function getChild($id)
    {
        return Db::table($this->tableName)
            ->where([$this->parentKey => $id])
            ->order("{$this->leftKey}")
            ->select();
    }

    /**
     * @param $parentId
     * @param array $data
     * @param string $position top|bottom
     * @return int|string
     * 添加新节点
     */
    public function insert($parentId, array $data = [], $position = "top")
    {
        $parent = $this->getItem($parentId);

        if (!$parent) {
            $parentId = 0;
            $level = 1;
            if ($position == "top") {
                $key = 1;
            }else{
                $key = Db::table($this->tableName)
                    ->max("{$this->rightKey}")+1;
            }
        }else{
            $key = ($position == "top")? $parent[$this->leftKey]+1:$parent[$this->rightKey];
            $level = $parent[$this->levelKey]+1;
        }

        Db::startTrans();
        //更新其他节点
        $sql = "UPDATE {$this->tableName} SET {$this->rightKey} = {$this->rightKey}+2,{$this->leftKey} = IF({$this->leftKey}>={$key},{$this->leftKey}+2,{$this->leftKey}) WHERE {$this->rightKey}>={$key}";
        try {
            Db::table($this->tableName)
                ->query($sql);

            $newNode[$this->parentKey] = $parentId;
            $newNode[$this->leftKey] = $key;
            $newNode[$this->rightKey] = $key + 1;
            $newNode[$this->levelKey] = $level;
            $tmpData = array_merge($newNode, $data);

            Db::table($this->tableName)->insert($tmpData);
            Db::commit();
            return true;
        }catch (Exception $e){
            Db::rollback();
            return false;
        }
    }

    /**
     * @param $id
     * @return bool
     * @throws Exception
     * 删除某个节点   包含了该节点的后代节点
     */
    public function delete($id)
    {
        $item = $this->getItem($id);
        if (!$item) {
            throw new Exception('没有该节点');
        }

        Db::startTrans();

        $keyWidth = $item[$this->rightKey] - $item[$this->leftKey]+1;

        //先删除节点及后代节点
        $condition[$this->leftKey] = ['>=', $item[$this->leftKey]];
        $condition[$this->rightKey] = ['<=', $item[$this->rightKey]];

        try {
            Db::table($this->tableName)
                ->where($condition)->delete();

            $sql = "UPDATE {$this->tableName} SET {$this->leftKey} = IF({$this->leftKey}>{$item[$this->leftKey]}, {$this->leftKey}-{$keyWidth}, {$this->leftKey}), {$this->rightKey} = {$this->rightKey}-{$keyWidth} WHERE {$this->rightKey}>{$item[$this->rightKey]}";
            //再移动节点
            Db::table($this->tableName)->query($sql);

            Db::commit();
            return true;
        }catch (Exception $e){
            Db::rollback();
            dump($e->getMessage());
            return false;
        }
    }

    /**
     * @param $id
     * @param $parentId
     * @param string $position bottom表示在后边插入   top表示开始插入
     * @return bool
     * @throws Exception
     * 将一个节点移动到另个一节点下
     */
    public function moveUnder($id, $parentId, $position = "bottom")
    {
        $item = $this->getItem($id);
        if (!$item) {
            throw new Exception('没有该节点');
        }

        $parent = $this->getItem($parentId);

        if (!$parent) {
            $level = 1;
            // 在顶部插入
            if ($position == 'top') {
                $nearKey = 0;
            } else {
                // 选择最大的右键作为开始
                $nearKey = Db::table($this->tableName)
                    ->max("{$this->rightKey}");
            }
        }else{
            $level = $parent[$this->levelKey] + 1;
            if ($position == 'top') {
                $nearKey = $parent[$this->leftKey];
            } else {
                //若在底部插入则起始键为父节点的右键减1
                $nearKey = $parent[$this->rightKey] - 1;
            }
        }

        return $this->move($id, $parentId, $nearKey, $level);
    }

    /**
     * @param $id
     * @param $nearId
     * @param string $position
     * @return bool
     * @throws Exception
     * 把主键为id的节点移动到主键为nearId的节点的前或者后
     */
    public function moveNear($id, $nearId, $position = 'after')
    {
        $item = $this->getItem($id);
        if (!$item) {
            throw new Exception("要移动的节点不存在");
        }

        $near = $this->getItem($nearId);
        if (!$near) {
            throw new Exception("附近的节点不存在");
        }

        $level = $near[$this->levelKey];

        //根据要移动的位置选择键
        if ($position == 'before') {
            $nearKey = $near[$this->leftKey] - 1;
        } else {
            $nearKey = $near[$this->rightKey];
        }

        //移动节点
        return $this->move($id, $near[$this->parentKey], $nearKey, $level);

    }

    /**
     * @param $id
     * @param $parentId
     * @param $nearKey
     * @param $level
     * @return bool
     * 移动节点
     */
    private function move($id, $parentId, $nearKey, $level)
    {
        $item = $this->getItem($id);

        //检查能否移动该节点若为移动到节点本身下则返回错误
        if ($nearKey >= $item[$this->leftKey] && $nearKey <= $item[$this->rightKey]) {
            return false;
        }


        $keyWidth = $item[$this->rightKey] - $item[$this->leftKey]+1;
        $levelWidth = $level-$item[$this->levelKey];

        if ($item[$this->rightKey] < $nearKey) {
            $treeEdit = $nearKey - $item[$this->leftKey]+1-$keyWidth;
            $sql = "UPDATE {$this->tableName} 
                    SET 
                    {$this->leftKey} = IF(
                        {$this->rightKey} <= {$item[$this->rightKey]},
                        {$this->leftKey} + {$treeEdit},
                        IF(
                            {$this->leftKey} > {$item[$this->rightKey]},
                            {$this->leftKey} - {$keyWidth},
                            {$this->leftKey}
                        )
                    ),
                    {$this->levelKey} = IF(
                        {$this->rightKey} <= {$item[$this->rightKey]},
                        {$this->levelKey} + {$levelWidth},
                        {$this->levelKey}
                    ),
                    {$this->rightKey} = IF(
                        {$this->rightKey} <= {$item[$this->rightKey]},
                        {$this->rightKey} + {$treeEdit},
                        IF(
                            {$this->rightKey} <= {$nearKey},
                            {$this->rightKey} - {$keyWidth},
                            {$this->rightKey}
                        )
                    ),
                    {$this->parentKey} = IF(
                        {$this->primaryKey} = {$id},
                        {$parentId},
                        {$this->parentKey}
                    )
                    WHERE 
                    {$this->rightKey} > {$item[$this->leftKey]}
                    AND 
                    {$this->leftKey} <= {$nearKey}";
            Db::table($this->tableName)->query($sql);
        }else{
            $treeEdit = $nearKey - $item[$this->leftKey]+1;

            $sql = "UPDATE {$this->tableName}
                    SET 
                    {$this->rightKey} = IF(
						{$this->leftKey} >= {$item[$this->leftKey]},
						{$this->rightKey} + {$treeEdit},
						IF(
							{$this->rightKey} < {$item[$this->leftKey]},
							{$this->rightKey} + {$keyWidth},
							{$this->rightKey}
						)
					),
					{$this->levelKey} = IF(
						{$this->leftKey} >= {$item[$this->leftKey]},
						{$this->levelKey} + {$levelWidth},
						{$this->levelKey}
					),
					{$this->leftKey} = IF(
						{$this->leftKey} >= {$item[$this->leftKey]},
						{$this->leftKey} + {$treeEdit},
						IF(
							{$this->leftKey} > {$nearKey},
							{$this->leftKey} + {$keyWidth},
							{$this->leftKey}
						)
					),
					{$this->parentKey} = IF(
						{$this->primaryKey} = {$id},
						{$parentId},
						{$this->parentKey}
					)
					WHERE
					{$this->rightKey} > {$nearKey}
					AND
					{$this->leftKey} < {$item[$this->rightKey]}";
            Db::table($this->tableName)->query($sql);
        }

        return true;

    }

    /**
     * @param $id
     * @return mixed
     * 根据ID获取某个节点
     */
    private function getItem($id)
    {
        if (!isset(self::$itemCache[$id])) {
            self::$itemCache[$id] =
                Db::table($this->tableName)
                ->field([$this->leftKey, $this->rightKey, $this->parentKey, $this->levelKey])
                ->where([$this->primaryKey => $id])
                ->find();
        }

        return self::$itemCache[$id];
    }

}