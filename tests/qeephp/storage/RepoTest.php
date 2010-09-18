<?php

namespace tests\qeephp\storage;

use tests\includes\TestCase;
use tests\qeephp\fixture\StorageFixture;
use tests\qeephp\fixture\models\Post;
use tests\qeephp\fixture\models\Comment;
use tests\qeephp\fixture\models\Revision;

use qeephp\storage\Repo;
use qeephp\tools\Logger;

require_once __DIR__ . '/../__init.php';

class RepoTest extends TestCase
{
    private $_default_adapter;
    private $_recordset;

    function test_select_adapter()
    {
        $domain = StorageFixture::DEFAULT_DOMAIN;
        $id = 1;

        /**
         * #BEGIN EXAMPLE
         *
         * 根据模型定义的存储域选择存储适配器
         *
         * @api Repo::set_dispatcher()
         * @api Repo::select_adapter()
         *
         * 设定指定存储域的调度方法，并通过 select_adapter() 在运行时选择实际的存储适配器对象。
         *
         * -  自定义的调度函数接受两个参数：存储域名称和附加参数。附加参数可能是主键值或模型对象实例。
         *    调度函数根据参数返回存储域节点名称，select_adapter() 根据调度函数的返回值构造完整的存储域名称，
         *    并返回相应的存储对象。
         *
         * -  例如主键值＝1，下述调度函数会产生节点名称 node1。最后实际的存储域名称就是 $domain.node1。
         *
         * -  如果指定的存储域没有指定调度函数，则返回该存储域对应的存储对象。
         */
        $dispatcher = function ($domain, $arg) {
            if ($arg instanceof BaseModel)
            {
                // 没有考虑复合主键的情况
                $id = intval($arg->id());
            }
            else
            {
                $id = intval($arg);
            }
            $node_index = (($id - 1) % 2) + 1;
            return "node{$node_index}";
        };

        Repo::set_dispatcher($domain, $dispatcher);
        $adapter = Repo::select_adapter($domain, $id);
        // #END EXAMPLE

        $this->assertType('qeephp\\storage\\adapter\\IAdapter', $adapter);
        $config = $adapter->config;
        $this->assertEquals('qeephp_test_db1', $config['database']);

        $adapter_second = Repo::select_adapter(StorageFixture::DEFAULT_DOMAIN, 2);
        Repo::del_dispatcher(StorageFixture::DEFAULT_DOMAIN);
        $this->assertType('qeephp\\storage\\adapter\\IAdapter', $adapter_second);
        $config = $adapter_second->config;
        $this->assertEquals('qeephp_test_db2', $config['database']);
        $this->assertFalse($adapter === $adapter_second);
    }

    function test_find_one()
    {
        $class = 'tests\\qeephp\\fixture\\models\\Post';

        /**
         * #BEGIN EXAMPLE
         *
         * 查询指定主键值的对象
         *
         * @api Repo::find_one()
         * @api BaseModel::find_one()
         *
         * 如果 find_one() 的第二个参数是整数，则暗示以该值为主键值进行查询。
         *
         * 提示：BaseModel::find_one() 调用 Repo::find_one() 进行查询操作。
         */
        $post = Post::find_one(5);
        // #END EXAMPLE
        $this->_check_post($post, 5);

        /**
         * #BEGIN EXAMPLE
         *
         * 使用更复杂的条件查询对象
         *
         * @api Repo::find_one()
         * @api BaseModel::find_one()
         *
         * find_one() 方法和其他对象的 find_one() 方法一样支持多样化的查询条件。
         * 参考 MySQLAdapter::find_one()。
         */
        $cond = array('post_id > ? AND post_id < ?', 3, 5);
        $post = Post::find_one($cond);
        // #END EXAMPLE
        $this->_check_post($post, 4);

        /**
         * #BEGIN EXAMPLE
         *
         * Repo 的对象缓存
         *
         * @api Repo::find_one()
         * @api BaseModel::find_one()
         *
         * 在当前请求执行过程中，曾经查询过的对象都会记录在 Repo 的对象缓存中，
         * 因此对同一个对象的重复查询不会返回对象的多个实例。
         *
         * 要避免对同一对象查询时造成多次存储查询操作，应该总是使用主键值（整数）
         * 或仅包含主键值的数组作为查询条件。
         */
        $post_id = 2;
        // 以下三次 find_one() 调用仅会进行一次存储查询操作
        $post_query1 = Post::find_one($post_id);
        $post_query2 = Post::find_one($post_id);
        $post_query3 = Post::find_one(array(Post::meta()->idname => $post_id));

        /**
         * 当使用不同的查询条件查询同一个对象时，可能进行多次存储查询。但除了对该对象的第一次查询，
         * 后续的查询结果都会被直接丢弃，仍然返回先前查询所获得的对象实例。
         */
        $post_query4 = Post::find_one('post_id > 1 AND post_id < 3');

        // 四次 find_one() 调用返回同一个对象实例
        $is_equals = (($post_query1 === $post_query2)
                     && ($post_query2 === $post_query3)
                     && ($post_query3 === $post_query4));
        // #END EXAMPLE
        $this->assertTrue($is_equals);

        /**
         * #BEGIN EXAMPLE
         *
         * 清除 Repo 的对象缓存
         *
         * @api Repo::find_one()
         * @api Repo::clean_cache();
         * @api BaseModel::find_one()
         *
         * 如果有必要，可以通过 Repo::clean_cache() 方法清除 Repo 的对象缓存。
         * 清除缓存后，对所有对象的查询将返回不同的实例。
         */
        $post3 = Post::find_one(3);
        Repo::clean_cache();
        $another_post3 = Post::find_one(3);
        $is_not_equals = ($post3 !== $another_post3);
        // #END EXAMPLE
        $this->assertTrue($is_not_equals);

        /**
         * #BEGIN EXAMPLE
         *
         * 清除指定对象的缓存
         *
         * @api Repo::find_one()
         * @api Repo::clean_cache();
         * @api BaseModel::find_one()
         * @api BaseModel::clear_cache()
         *
         * 调用对象的 clean_cache() 方法，可以清除掉该对象在 Repo 中的缓存。
         * 清除缓存后，对同一对象的查询将返回不同的实例。
         *
         * 提示：BaseModel::clean_cache() 调用 Repo::clean_cache() 清除指定对象的缓存。
         */
        $post2 = Post::find_one(2);
        $post4 = Post::find_one(4);
        $post4->clean_cache();
        $another_post2 = Post::find_one(2);
        $another_post4 = Post::find_one(4);
        $is_equals = ($post2 === $another_post2);
        $is_not_equals = ($post4 !== $another_post4);
        // #END EXAMPLE
        $this->assertTrue($is_equals);
        $this->assertTrue($is_not_equals);
    }

    function test_find_multi()
    {
        $post_class = 'tests\\qeephp\\fixture\\models\\Post';

        /**
         * #BEGIN EXAMPLE
         *
         * 查询指定主键值的多个对象
         *
         * @api Repo::find_multi()
         * @api BaseModel::find_multi()
         *
         * find_multi() 只支持使用一个主键的对象。$cond 只能是包含多个主键值的数组。
         *
         * 与 find_one() 一样，find_multi() 会缓存查询到的对象，并尽可能减少不必要的存储查询操作。
         *
         * 提示：BaseModel::find_multi() 调用 Repo::find_multi() 进行查询操作。
         */
        $post_id_list = array(1, 3, 5);
        $posts = Post::find_multi($post_id_list);
        $other_posts = Post::find_multi($post_id_list);
        // #END EXAMPLE
        $this->assertEquals(3, count($posts));
        $id = 1;
        foreach ($posts as $post_id => $post)
        {
            $this->_check_post($post, $post_id);
            $this->assertEquals($id, $post_id);
            $this->assertTrue($post === $other_posts[$post_id]);
            $id += 2;
        }
    }

    function test_find()
    {
        $post_class = 'tests\\qeephp\\fixture\\models\\Post';
        /**
         * #BEGIN EXAMPLE
         * 
         * 按照任意条件查询对象
         *
         * @api Repo::find()
         * @api BaseModel::find()
         *
         * find() 方法的 $cond 参数和 IAdapter::find() 方法相同，可以使用各种类型的查询条件。
         *
         * 提示：BaseModel::find() 调用 Repo::find() 进行查询操作。
         *
         * 注意: find() 方法不会缓存查询得到的对象。
         */
        $posts = Post::find(array('post_id > ? AND post_id < ?', 1, 5))->fetch_all();
        // #END EXAMPLE

        $this->assertType('array', $posts);
        foreach($posts as $post)
        {
            $this->assertType($post_class, $post);
            $this->_check_post($post, $post->id());
        }
    }

    function test_create()
    {
        /**
         * #BEGIN EXAMPLE
         *
         * 保存新创建的对象
         *
         * @api Repo::save();
         * @api Repo::create()
         * @api BaseModel::save()
         *
         * 使用 new 构造的对象实例，在调用 Repo::save() 保存时会自动调用 Repo::create() 方法。
         *
         * 在创建新对象时，create() 和 save() 方法会返回新对象的主键值。
         *
         * 提示：BaseModel::save() 调用 Repo::save() 进行对象的存储操作。
         */
        $post_id = 99;
        $post = new Post();
        $post->postId = $post_id;
        $post->title  = 'post 99';
        $post->author = 'author 99';
        $post->click_count = 99;
        $id = $post->save();
        // # END EXAMPLE
        $this->assertEquals($post_id, $id);
        $record = $this->_get_post_record(99);
        $this->assertType('array', $record);
        $this->assertEquals(99, $record['post_id']);
        $this->assertEquals('post 99', $record['title']);
        $this->assertEquals('author 99', $record['author']);
        $this->assertEquals(99, $record['click_count']);

        /**
         * #BEGIN EXAMPLE
         *
         * 在创建对象时使用自增字段
         *
         * @api Repo::save();
         * @api Repo::create()
         * @api BaseModel::save()
         *
         * 如果对象的存储（例如 MySQL）使用了自增字段，那么在调用 save() 前不用为该字段对应的属性指定值。
         * save() 成功保存对象后，会确保对象的 id() 方法返回为该对象自动分配的主键值。
         */
        $comment = new Comment();
        $comment->post_id = $post_id;
        $comment->created = time();
        $comment->author  = 'dualface';
        $comment->body    = 'new comment';
        $id = $comment->save();
        // #END EXAMPLE
        $this->assertType('int', $id);
        $this->assertEquals($id, $comment->id());
        $this->assertEquals($id, $comment->comment_id);


        /**
         * #BEGIN EXAMPLE
         *
         * 在创建对象时使用复合主键
         *
         * @api Repo::save();
         * @api Repo::create()
         * @api BaseModel::save()
         *
         * 如果使用了复合主键，则需要提供所有主键值。但如果某个主键是自增字段，则可以不提供该主键的值。
         *
         * 使用复合主键时，create() 和 save() 方法返回包含所有主键值的数组。
         */
        // Revision 对象的 rev_id 主键是自增资段，而 post_id 则是非自增主键。
        $rev = new Revision();
        $rev->postId  = 1;
        $rev->created = time();
        $rev->body    = 'post 1 rev';
        $id = $rev->save();
        // #END EXAMPLE
        $this->assertType('array', $id);
        $this->assertArrayHasKey('rev_id', $id);
        $this->assertArrayHasKey('postId', $id);
        $obj_id = $rev->id();
        ksort($obj_id, SORT_ASC);
        ksort($id, SORT_ASC);
        $this->assertEquals($obj_id, $id);
    }

    function test_simple_update()
    {
        /**
         * #BEGIN EXAMPLE
         *
         * 保存对象的改动
         *
         * @api Repo::save()
         * @api Repo::update()
         * @api BaseModel::save()
         *
         * 使用 find* 方法读取出来的对象，调用 Repo::save() 可将对象的改动保存起来。
         * 
         * 如果确实更新了存储的数据，则 save() 方法返回 true，否则返回 false。
         *
         * 提示：BaseModel::save() 调用 Repo::save() 进行对象的存储操作。
         */
        $post = Post::find_one(1);
        $post->title = strrev($post->title);
        $success = $post->save();
        // #END EXAMPLE
        $this->assertTrue($success);
        $post->clean_cache();
        $another_post = Post::find_one(1);
        $this->assertFalse($post === $another_post);
        $this->assertEquals($post->title,  $another_post->title);
        $this->assertEquals($post->postId, $another_post->postId);
    }

    function test_update_changed_props()
    {
        /**
         * #BEGIN EXAMPLE
         *
         * 仅保存对象改动过的属性
         *
         * @api Repo::save()
         * @api Repo::update()
         * @api BaseModel::save()
         *
         * 默认情况下，保存改动后的对象到存储时，会保存对象的所有属性，即便只有部分属性发生了改变。
         * 关于更新策略的详细说明，参考 Meta 类的文档。
         */
        // 对同一对象读取两次，模拟并发的请求
        Repo::clean_cache();
        $post = Post::find_one(1);
        $post->clean_cache();
        $post2 = Post::find_one(1);

        // 分别修改两个实例的不同属性，并保存
        $post->title = 'new post 1';
        $post2->author = 'new post 1 author';
        $post->save();
        $post2->save();

        // 重新读取对象，可以看到 title 和 author 属性都发生了变化
        Repo::clean_cache();
        $post3 = Post::find_one(1);
        $is_title_euqals = ($post3->title == $post->title);
        $is_author_equals = ($post3->author == $post2->author);
        // #END EXAMPLE
        $this->assertTrue($is_title_euqals);
        $this->assertTrue($is_author_equals);
    }

    function test_update_check_changed_props()
    {
        /**
         * #BEGIN EXAMPLE
         *
         * 更新时检查改动过的属性，避免并发冲突
         *
         * @api Repo::save()
         * @api Repo::update()
         * @api BaseModel::save()
         *
         * 合理设置模型类的 @update 标注，可以一定程度上避免并发更新冲突。
         *
         * 例如 Post 类的 @update 设置为 changed, check_changed。
         * 则在保存改动过的 Post 对象时，仅会保存改动过的属性。
         * 并对改动改动过的属性进行检查，确保存储中该属性的值没有发生变化。
         *
         * 有关 @update 的详细设定，请参考 Meta 文档。
         */
        // 对同一对象读取两次，模拟并发的请求
        Repo::clean_cache();
        $post = Post::find_one(1);
        $post->clean_cache();
        $post2 = Post::find_one(1);

        // 更改两个对象的 title 属性
        $post->title = 'changed post 1';
        $post2->title = 'changed post 1 again';

        // 保存时，后一次 save() 将会返回 false
        $is_true = $post->save();
        $is_false = $post2->save();
        // #END EXAMPLE
        $this->assertTrue($is_true);
        $this->assertFalse($is_false);
    }

    function test_update_prop_by_arithmetic()
    {
        /**
         * #BEGIN EXAMPLE
         *
         * 使用算术运算更新对象属性
         *
         *
         *
         */
        $post_id = 1;
        $post = Post::find_one($post_id);
        $post->clean_cache();
        $post->click_count += 100;
        $post->title = strrev($post->title);

        $post2 = Post::find_one($post_id);
        $post2->click_count += 100;

        $result = $post->save();
        $result2 = $post2->save();
        // #END EXAMPLE

        $this->assertTrue($result);
        $this->assertTrue($result2);

        $record = $this->_get_post_record($post_id);
        $this->assertType('array', $record);
        $this->assertEquals($post_id, $record['post_id']);
        $this->assertEquals(strrev($this->_recordset[$post_id]['title']), $record['title']);
        $this->assertEquals($this->_recordset[$post_id]['click_count'] + 200, $record['click_count']);
    }

    function test_del()
    {
        $this->markTestIncomplete();
    }

    function test_erase()
    {
        $this->markTestIncomplete();
    }

    protected function setup()
    {
        StorageFixture::set_default_mysql_domain_config();
        StorageFixture::set_second_domain_config();
        $this->_cleanup();

        $this->_default_adapter = Repo::select_adapter(StorageFixture::DEFAULT_NODE);
        $this->_default_adapter->set_logger(Logger::instance('test'));
        $this->_create_posts();
    }

    protected function teardown()
    {
        $this->_cleanup();
    }

    private function _cleanup()
    {
        $adapter = Repo::select_adapter(Post::meta()->domain());
        $adapter->del('post', null);
        $adapter->del('comment', null);
        $this->_default_adapter = null;
        $this->_recordset = null;
        Repo::clean_cache();
    }

    private function _create_recordset($collection, array $recordset, $idname = null)
    {
        foreach ($recordset as $offset => $record)
        {
            $result = $this->_default_adapter->insert($collection, $record);
            if ($idname)
            {
                $recordset[$offset][$idname] = $result;
            }
        }
        return $recordset;
    }

    private function _create_posts()
    {
        $this->_recordset = StorageFixture::post_recordset();
        $this->_create_recordset(Post::meta()->collection(), $this->_recordset);
    }

    private function _create_revisions()
    {
        $recordset = StorageFixture::revisions_recordset();
        $meta = Revision::meta();
        return $this->_create_recordset($meta->collection(), $recordset, $meta->autoincr_idname);
    }

    private function _check_post($post, $post_id)
    {
        $this->assertType(Post::meta()->class, $post);
        $record = $this->_recordset[$post_id];
        $record['postId'] = $post_id;
        unset($record['post_id']);
        foreach ($record as $key => $value)
        {
            $this->assertEquals($value, $post->$key);
        }
    }

    private function _get_post_record($post_id)
    {
        $meta = Post::meta();
        $cond = array($meta->props_to_fields[$meta->idname] => $post_id);
        return $this->_default_adapter->find_one($meta->collection(), $cond);
    }
}

