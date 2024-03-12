<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}
class SpiderTracker_Widget extends Typecho_Widget implements Widget_Interface_Do
{
    /**
     * 全局选项
     *
     * @access protected
     * @var Widget_Options
     */
    protected $options;

    /**
     * 用户对象
     *
     * @access protected
     * @var Widget_User
     */
    protected $user;

    /**
     * 安全模块
     *
     * @var Widget_Security
     */
    protected $security;

    /**
     * 数据库对象
     *
     * @access protected
     * @var Typecho_Db
     */
    protected $db;

    /**
     * 搜索引擎列表
     *
     * @var array
     */
    protected $bots;
    /**
     * 当前页
     *
     * @access private
     * @var integer
     */
    private $_currentPage;

    /**
     * 生成分页的内容
     *
     * @access private
     * @var array
     */
    private $_pageRow = array();

    /**
     * 分页计算对象
     *
     * @access private
     * @var Typecho_Db_Query
     */
    private $_countSql;

    /**
     * 所有文章个数
     *
     * @access private
     * @var integer
     */
    private $_total = false;

    /**
     * 构造函数,初始化组件
     *
     * @access public
     * @param mixed $request request对象
     * @param mixed $response response对象
     * @param mixed $params 参数列表
     */
    public function __construct($request, $response, $params = NULL)
    {
        parent::__construct($request, $response, $params);

        /** 初始化数据库 */
        $this->db = Typecho_Db::get();

        /** 初始化常用组件 */
        $this->options = $this->widget('Widget_Options');
        $this->user = $this->widget('Widget_User');
        $this->security = $this->widget('Widget_Security');

        $this->bots = SpiderTracker_Util::getBotsList();
    }
    public function select()
    {
        return $this->db->select()->from('table.spider_tracker_logs');
    }
    /**
     * 执行函数
     *
     * @access public
     * @return void
     */
    public function execute()
    {
        /** 初始化分页变量 */
        $pageSize = SpiderTracker_Util::getConfig()->pageSize;
        $pageSize = intval($pageSize) > 0 ? $pageSize : 20;
        $this->parameter->setDefault(array(
            'pageSize' => $pageSize,
            'bot' => $this->request->get('bot'),
            'ip' => $this->request->get('ip')
        ), false);

        $this->_currentPage = $this->request->get('page', 1);

        $hasPushed = false;

        /** 构建基础查询 */
        $select = $this->select();

        if (!empty($this->parameter->bot)) {
            $select->where('table.spider_tracker_logs.bot = ?', $this->parameter->bot);
        }
        if (!empty($this->parameter->ip)) {
            $select->where('table.spider_tracker_logs.ip = ?', $this->parameter->ip);
        }

        /** 如果已经提前压入则直接返回 */
        if ($hasPushed) {
            return;
        }

        /** 仅输出文章 */
        $this->_countSql = clone $select;

        /** 提交查询 */
        $select->order('table.spider_tracker_logs.ltime', Typecho_Db::SORT_DESC)
            ->page($this->_currentPage, $this->parameter->pageSize);

        $this->db->fetchAll($select, array($this, 'push'));
    }
    /**
     * 将每行的值压入堆栈
     *
     * @access public
     * @param array $value 每行的值
     * @return array
     */
    public function push(array $value)
    {
        $value = $this->filter($value);
        return parent::push($value);
    }

    /**
     * 通用过滤器
     *
     * @access public
     * @param array $value 需要过滤的行数据
     * @return array
     * @throws Typecho_Widget_Exception
     */
    public function filter(array $value)
    {
        $value['botName'] = array_key_exists($value['bot'], $this->bots) ? $this->bots[$value['bot']] : $value['bot'];
        $value['theId'] = 'robots-log-' . $value['lid'];
        return $value;
    }

    /**
     * 输出分页
     *
     * @access public
     * @param string $prev 上一页文字
     * @param string $next 下一页文字
     * @param int $splitPage 分割范围
     * @param string $splitWord 分割字符
     * @param string $template 展现配置信息
     * @return void
     */
    public function pageNav()
    {
        $query = $this->request->makeUriByRequest('page={page}');
        /** 使用盒状分页 */
        $nav = new Typecho_Widget_Helper_PageNavigator_Box(
            false === $this->_total ? $this->_total = $this->size($this->_countSql) : $this->_total,
            $this->_currentPage,
            $this->parameter->pageSize,
            $query
        );
        $nav->render('&laquo;', '&raquo;');
    }

    /**
     * @return int
     */
    public function getTotal()
    {
        if (false === $this->_total) {
            $this->_total = $this->size($this->_countSql);
        }

        return $this->_total;
    }

    public function size(Typecho_Db_Query $condition)
    {
        return $this->db->fetchObject($condition
            ->select(array('COUNT(DISTINCT table.spider_tracker_logs.lid)' => 'num'))
            ->from('table.spider_tracker_logs')
            ->cleanAttribute('group'))->num;
    }

    public function deleteLogs($tag)
    {
        $current_timestamp = time();
        $deleteCount = 0;
        if ($tag == 0) {
            $logs = $this->request->filter('int')->getArray('lid');
            $result = $this->db->query($this->db->delete('table.spider_tracker_logs')->where('table.spider_tracker_logs.lid IN ?', $logs));
            if ($result)
            $deleteCount++;
        } else if ($tag == 1) {
            $result = $this->db->query($this->db->delete('table.spider_tracker_logs'));
            if ($result)
            $deleteCount++;
        } else if ($tag == 2) {
            $result = $this->db->query($this->db->delete('table.spider_tracker_logs')->where('table.spider_tracker_logs.ltime < ?', $current_timestamp - 24 * 60 * 60));
            if ($result)
            $deleteCount++;
        } else if ($tag == 3) {
            $result = $this->db->query($this->db->delete('table.spider_tracker_logs')->where('table.spider_tracker_logs.ltime < ?', $current_timestamp - 7 * 24 * 60 * 60));
            if ($result)
            $deleteCount++;
        }else if ($tag == 4) {
            $result = $this->db->query($this->db->delete('table.spider_tracker_logs')->where('table.spider_tracker_logs.ltime < ?', $current_timestamp - 30 * 24 * 60 * 60));
            if ($result)
            $deleteCount++;
        }


        /** 设置提示信息 */
        $this->widget('Widget_Notice')->set(
            $deleteCount > 0 ? _t('日志已经被删除') : _t('没有日志被删除'),
            $deleteCount > 0 ? 'success' : 'notice'
        );
    }


    public function action()
    {
        $this->security->protect();
        $this->on($this->request->is('do=delete'))->deleteLogs(0); 
        $this->on($this->request->is('do=deleteAll'))->deleteLogs(1); 
        $this->on($this->request->is('do=deleteDay'))->deleteLogs(2); 
        $this->on($this->request->is('do=deleteWeek'))->deleteLogs(3); 
        $this->on($this->request->is('do=deleteMonth'))->deleteLogs(4); 
        $this->response->goBack();
    }
}