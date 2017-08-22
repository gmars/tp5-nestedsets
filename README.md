# tp5-nestedsets
>本扩展包是tp5的nestedsets包，使用了部分tp5的特性实现了关系型数据库中特殊数据结构的处理。

##安装方法
先安装composer如果不知道怎么安装使用composer请自行百度。
打开命令行工具切换到你的tp5项目根目录

```
composer require gmars/tp5-nestedsets
```
如果该方法报错请按照以下方式操作：

1. 打开项目根目录下的composer.json
2. 在require中添加"gmars/tp5-nestedsets": "dev-master"
3. 运行composer update

添加后composer.json应该有这样的部分：

```
    "require": {
        "php": ">=5.4.0",
        "topthink/framework": "^5.0",
        "gmars/tp5-nestedsets": "dev-master"
    },
```

##获取nestedsets实例
###结合模型使用
>一般使用nestedsets是结合模型使用的，但是此扩展中并没有使用tp5中模型的特性进行增删改查，那样的话让我们的扩展包会显得更为厚重。但是模型的特性在我们的使用中是有必要的。

***
在项目中需要配置的参数和你的数据表字段对应就行：

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
    
我的数据表结构为:
```mysql
CREATE TABLE `user` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) DEFAULT NULL,
  `left_key` int(11) unsigned DEFAULT NULL,
  `right_key` int(11) unsigned DEFAULT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `level` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8
```

***
####在模型中配置nestedsets并使用：
在模型中配置的方式为在模型中定义一个public $nestedConfig属性。注意，名称必须是nestedConfig

例如:
```php
class User extends Model
{
    public $nestedConfig = [
        'leftKey' => 'lft',
        'rightKey' => 'rgt'
    ];

}
```
然后就可以实例化使用了

```php
$userModel = new User();
$nestedObj = new NestedSets($userModel);
```
这样就实例化了一个nestedsets对象，紧接着就可以调用其中的方法进行使用了。

**实例化时传入配置参数：**

其实上边直接配置已经很方便了。但是你不想在模型中配置该参数也可以在实例化时传入配置，例如：
```php
$userModel = new User();
$nested = new NestedSets($userModel, "lftkey", "rgtkey");
```
这种方式也是允许的，但是如果模型中的配置和直接传参都使用时模型中配置的参数不会生效只生效实例化时传入的参数。

###原生配置使用

这种方式是考虑如果你存在的表没有必要创建模型时的使用。

这时候就没有模型供我们使用，所以实例化时第一个参数为表名，例如：

```php
$userModel = new User();
$nested = new NestedSets("user");
```
需要注意的是，传入的表名必须是完整表名。这种方式如果要对字段配置只能在实例化时传入参数。

##在项目中使用nestedsets

**如果你要创建一个节点：**
```php
$data = ['name' => "刘欢"];
$parentId = 6;
$nestedObj = new NestedSets("user");
$nestedObj->insert($parentId, $data);
```
parentId是要创建节点的父亲节点。

data是数据表中其他的字段例如名称、描述信息等，必须是数组

insert方法的本来形式如下：
```php
/**
* $data必须是数组，就算只有一个字段都必须写成数组形式
* $position是位置，支持在id为parentId的元素的子元素最前和最后插入有top和bottom两个值供选择
*/
public function insert($parentId, array $data = [], $position = "top")
```

**删除节点**
>删除节点会删除该节点下的所有后代节点，这个在逻辑上来说是合理的。

```php
/**
* 传入一个参数为要删除节点的id值
*/
$nested = new NestedSets("user");
$nested->delete(8);
```

**移动某一个节点成为另一个的子节点**
>移动一个节点成为另一个节点的子节点的操作在实际使用中使用比较广泛

```php
/**
* 将id为7的节点移动到id为2的节点上
* 如果要将id为7的节点移动为父节点那么第二个参数为0即可
*/
$nested = new NestedSets("user");
$nested->moveUnder(7, 2);

//需要注意的是moveUnder支持三个参数。
//第三个参数表示移动到该父节点的其他子节点之前还是之后
public function moveUnder($id, $parentId, $position = "bottom")
```

**移动某一个节点到另一个节点的前或者后**
>这种操作多见于改变节点的顺序上实用性也非常强

```php
//将id为7的节点移到id为8的节点旁，默认是之后
$nested = new NestedSets("user");
$nested->moveNear(7, 8);
```
该方法的原型为：
```php
//第三个参数为移动到参考节点之前或之后如果要移到之前请传入before
public function moveNear($id, $nearId, $position = 'after')
```

>可能有人会疑惑该扩展为何没有修改节点的方法。其实这不是nestedsets该做的事情。对于节点的修改在数据结构的层面来讲就是节点位置的移动。至于数据表中其他字段的修改，例如name,description等则使用框架给我们提供的字段修改的方法要更加方便。