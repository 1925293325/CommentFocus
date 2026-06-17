<?php
/**
 * 精选评论插件
 *
 * @package CommentFocus
 * @author Lin.
 * @version 1.1.0
 * @link https://linyu.live
 */
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

class CommentFocus_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 激活插件
     */
    public static function activate()
    {
        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();

        try {
            $columns = $db->fetchAll("SHOW COLUMNS FROM `{$prefix}comments`");
            $fieldExists = false;
            $featuredAtExists = false;

            foreach ($columns as $col) {
                if ($col['Field'] == 'featured') {
                    $fieldExists = true;
                }
                if ($col['Field'] == 'featured_at') {
                    $featuredAtExists = true;
                }
            }

            // 创建 featured 字段（兼容旧版本）
            if (!$fieldExists) {
                $db->query("ALTER TABLE `{$prefix}comments` ADD `featured` TINYINT(1) DEFAULT 0");
            }

            // 创建 featured_at 字段（记录精选时间，用于排序）
            if (!$featuredAtExists) {
                $db->query("ALTER TABLE `{$prefix}comments` ADD `featured_at` INT(10) DEFAULT 0");
            }
        } catch (Exception $e) {
            // 忽略错误
        }

        // 挂载后台钩子
        Typecho_Plugin::factory('admin/footer.php')->end = array(__CLASS__, 'adminFooter');

        // 挂载短代码解析
        Typecho_Plugin::factory('Widget_Abstract_Contents')->contentEx = array(__CLASS__, 'parseShortcode');

        return '精选评论插件已启用（v1.1.0）';
    }

    /**
     * 禁用插件
     */
    public static function deactivate()
    {
        return '精选评论插件已禁用';
    }

    /**
     * 插件配置
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        // 基本设置
        $enable = new Typecho_Widget_Helper_Form_Element_Radio(
            'enable',
            array('1' => '启用', '0' => '禁用'),
            '1',
            '启用插件',
            '是否启用精选评论功能'
        );
        $form->addInput($enable);

        $button_text = new Typecho_Widget_Helper_Form_Element_Text(
            'button_text',
            NULL,
            '设为精选',
            '按钮文字',
            '后台评论列表显示的按钮文字'
        );
        $form->addInput($button_text);

        $display_num = new Typecho_Widget_Helper_Form_Element_Text(
            'display_num',
            NULL,
            '10',
            '显示数量',
            '前台最多显示的精选评论数量'
        );
        $form->addInput($display_num);

        // 排序设置
        $sort_order = new Typecho_Widget_Helper_Form_Element_Select(
            'sort_order',
            array(
                'newest_featured' => '最新精选在前（后设为精选的排在前面）',
                'oldest_featured' => '最早精选在前（先设为精选的排在前面）',
                'newest_comment'  => '最新评论在前（按评论发表时间，新的在前）',
                'oldest_comment'  => '最早评论在前（按评论发表时间，旧的在先）',
            ),
            'newest_featured',
            '前台排序方式',
            '选择精选评论在前台的显示顺序'
        );
        $form->addInput($sort_order);

        $show_post_title = new Typecho_Widget_Helper_Form_Element_Radio(
            'show_post_title',
            array('1' => '显示', '0' => '隐藏'),
            '1',
            '显示文章标题',
            '是否显示评论所属文章的标题（点击可跳转到对应文章）'
        );
        $form->addInput($show_post_title);

        // 自定义 CSS
        $custom_css = new Typecho_Widget_Helper_Form_Element_Textarea(
            'custom_css',
            NULL,
            '',
            '自定义 CSS',
            '在此处编写 CSS 可直接覆盖前端的默认样式。示例：<br><code>.featured-comments { background: #fafafa; }</code><br>留空则使用插件默认样式。'
        );
        $custom_css->input->setAttribute('style', 'height: 120px; font-family: monospace; font-size: 13px;');
        $form->addInput($custom_css);
    }

    /**
     * 个人配置
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form) {}

    /**
     * 获取所有评论的精选状态
     */
    private static function getFeaturedStatus()
    {
        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();

        $result = array();

        try {
            $rows = $db->fetchAll($db
                ->select('coid, featured')
                ->from("{$prefix}comments")
                ->where("featured = 1")
            );

            foreach ($rows as $row) {
                $result[$row['coid']] = intval($row['featured']);
            }
        } catch (Exception $e) {
            // 忽略错误
        }

        return $result;
    }

    /**
     * 后台脚注注入 JavaScript
     */
    public static function adminFooter()
    {
        $request = Typecho_Request::getInstance();
        $uri = $request->getRequestUri();

        // 只在评论管理页面注入
        if (strpos($uri, 'manage-comments.php') === false) {
            return;
        }

        // 处理 AJAX 请求
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'comment_focus_toggle') {
            self::handleAjaxRequest();
        }

        // 获取所有精选评论的状态
        $featuredStatus = self::getFeaturedStatus();
        $featuredJson = json_encode($featuredStatus, JSON_UNESCAPED_UNICODE);

        $options = Typecho_Widget::widget('Widget_Options');
        $pluginOptions = $options->plugin('CommentFocus');
        $buttonText = isset($pluginOptions->button_text) ? $pluginOptions->button_text : '设为精选';

        echo <<<HTML
<style>
.cf-btn {
    display: inline;
    margin: 0 0 0 4px;
    padding: 2px 8px;
    border: 1px solid #d9d9d9;
    border-radius: 3px;
    background: #fff;
    color: #595959;
    text-decoration: none;
    font-size: 12px;
    cursor: pointer;
    line-height: 1.4;
    transition: all 0.2s ease;
    vertical-align: middle;
}
.cf-btn:hover {
    background: #f5f5f5;
    border-color: #bfbfbf;
    color: #262626;
}
.cf-btn.featured {
    background: #52c41a;
    color: white;
    border-color: #52c41a;
}
.cf-btn.featured:hover {
    background: #389e0d;
    border-color: #389e0d;
}
.cf-btn.loading {
    opacity: 0.6;
    cursor: wait;
}
/* 强制展开后台评论，操作按钮默认全部显示 */
.comment-content,
.comment-reply-content {
    max-height: none !important;
    overflow: visible !important;
}
.comment-operate,
.comment-meta,
.comment-action {
    display: inline-block !important;
    visibility: visible !important;
    opacity: 1 !important;
}

</style>
<script>
(function() {
    'use strict';

    var buttonText = '{$buttonText}';
    var featuredStatus = {$featuredJson};

    function addButtons() {
        var rows = document.querySelectorAll('table tbody tr');

        rows.forEach(function(row) {
            if (row.querySelector('.cf-btn')) {
                return;
            }

            var checkbox = row.querySelector('input[type="checkbox"]');
            if (!checkbox) {
                return;
            }

            var coid = checkbox.value;
            if (!coid || isNaN(coid)) {
                return;
            }

            var isFeatured = featuredStatus[coid] === 1;

            // 找到操作列
            var actionCell = null;
            var cells = row.querySelectorAll('td');
            if (cells.length > 0) {
                actionCell = cells[cells.length - 1];
            }

            if (!actionCell) {
                return;
            }

            // 查找"拉黑"链接，把精选按钮插入到它后面
            var links = actionCell.querySelectorAll('a');
            var targetLink = null;
            links.forEach(function(link) {
                if (link.textContent.trim() === '拉黑') {
                    targetLink = link;
                }
            });

            // 创建按钮
            var button = document.createElement('a');
            button.href = 'javascript:;';
            button.className = isFeatured ? 'cf-btn featured' : 'cf-btn';
            button.setAttribute('data-coid', coid);
            button.setAttribute('data-featured', isFeatured ? '1' : '0');
            button.textContent = isFeatured ? '已精选' : buttonText;
            button.title = isFeatured ? '点击取消精选' : '点击设为精选';

            button.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                handleButtonClick(this);
            });

            // 插入：如果找到"拉黑"，跟在它后面；否则追加到操作列末尾
            if (targetLink && targetLink.nextSibling) {
                targetLink.parentNode.insertBefore(button, targetLink.nextSibling);
            } else if (targetLink) {
                targetLink.parentNode.appendChild(button);
            } else {
                actionCell.appendChild(document.createTextNode(' '));
                actionCell.appendChild(button);
            }
        });
    }

    function handleButtonClick(button) {
        var coid = button.getAttribute('data-coid');
        if (!coid) {
            return;
        }

        var originalText = button.textContent;
        var originalClass = button.className;
        var originalFeatured = button.getAttribute('data-featured');
        button.textContent = '处理中...';
        button.className = 'cf-btn loading';

        var formData = new FormData();
        formData.append('action', 'comment_focus_toggle');
        formData.append('cid', coid);

        fetch('', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function(response) {
            return response.text().then(function(text) {
                return {
                    status: response.status,
                    text: text
                };
            });
        })
        .then(function(result) {
            button.classList.remove('loading');

            if (result.status === 200) {
                try {
                    var data = JSON.parse(result.text);

                    if (data.success) {
                        if (data.featured) {
                            button.textContent = '已精选';
                            button.className = 'cf-btn featured';
                            button.title = '点击取消精选';
                            button.setAttribute('data-featured', '1');
                            featuredStatus[coid] = 1;
                        } else {
                            button.textContent = buttonText;
                            button.className = 'cf-btn';
                            button.title = '点击设为精选';
                            button.setAttribute('data-featured', '0');
                            delete featuredStatus[coid];
                        }
                    } else {
                        alert('操作失败: ' + (data.message || '未知错误'));
                        button.textContent = originalText;
                        button.className = originalClass;
                        button.setAttribute('data-featured', originalFeatured);
                    }
                } catch (e) {
                    alert('服务器返回了非JSON格式的数据');
                    button.textContent = originalText;
                    button.className = originalClass;
                    button.setAttribute('data-featured', originalFeatured);
                }
            } else {
                alert('请求失败，状态码: ' + result.status);
                button.textContent = originalText;
                button.className = originalClass;
                button.setAttribute('data-featured', originalFeatured);
            }
        })
        .catch(function(error) {
            button.classList.remove('loading');
            alert('网络错误: ' + error.message);
            button.textContent = originalText;
            button.className = originalClass;
            button.setAttribute('data-featured', originalFeatured);
        });
    }

    setTimeout(addButtons, 100);

    var observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.addedNodes.length) {
                setTimeout(addButtons, 100);
            }
        });
    });

    observer.observe(document.body, {
        childList: true,
        subtree: true
    });

})();
</script>
HTML;
    }

    /**
     * 处理 AJAX 请求
     */
    private static function handleAjaxRequest()
    {
        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        $user = Typecho_Widget::widget('Widget_User');
        if (!$user->hasLogin()) {
            self::outputJson(array(
                'success' => false,
                'message' => '未登录'
            ));
        }

        $cid = isset($_POST['cid']) ? intval($_POST['cid']) : 0;
        if ($cid <= 0) {
            self::outputJson(array(
                'success' => false,
                'message' => '无效的评论ID'
            ));
        }

        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();

        try {
            $row = $db->fetchRow($db
                ->select('featured')
                ->from("{$prefix}comments")
                ->where("coid = ?", $cid)
            );

            if (!$row) {
                self::outputJson(array(
                    'success' => false,
                    'message' => '评论不存在'
                ));
            }

            $newStatus = $row['featured'] ? 0 : 1;
            $updateData = array('featured' => $newStatus);

            // 设为精选时记录时间，取消精选时清零
            if ($newStatus) {
                $updateData['featured_at'] = time();
            } else {
                $updateData['featured_at'] = 0;
            }

            $db->query($db
                ->update("{$prefix}comments")
                ->rows($updateData)
                ->where("coid = ?", $cid)
            );

            self::outputJson(array(
                'success' => true,
                'featured' => $newStatus,
                'message' => $newStatus ? '已设为精选' : '已取消精选'
            ));

        } catch (Exception $e) {
            self::outputJson(array(
                'success' => false,
                'message' => '服务器错误: ' . $e->getMessage()
            ));
        }
    }

    /**
     * 输出 JSON 响应
     */
    private static function outputJson($data)
    {
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * 获取文章链接
     */
    private static function getPostPermalink($cid, $slug, $type = 'post')
    {
        $options = Typecho_Widget::widget('Widget_Options');

        if (empty($slug) || empty($cid)) {
            return '#';
        }

        try {
            $routeParams = array('cid' => $cid, 'slug' => $slug);
            $permalink = Typecho_Router::url($type, $routeParams, $options->index);

            if (!empty($permalink)) {
                return $permalink;
            }
        } catch (Exception $e) {
            // 降级处理
        }

        try {
            $permalink = Typecho_Common::url('/archives/' . $cid . '/', $options->index);
            return $permalink;
        } catch (Exception $e) {
            // 最终降级
        }

        return rtrim($options->siteUrl, '/') . '/archives/' . $cid . '/';
    }

    /**
     * 解析短代码
     */
    public static function parseShortcode($content, $widget)
    {
        if (strpos($content, '[featured_comments]') === false) {
            return $content;
        }

        $options = Typecho_Widget::widget('Widget_Options');
        $pluginOptions = $options->plugin('CommentFocus');

        if (!isset($pluginOptions->enable) || $pluginOptions->enable != '1') {
            return $content;
        }

        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();

        $displayNum = isset($pluginOptions->display_num) ? intval($pluginOptions->display_num) : 10;
        $showPostTitle = isset($pluginOptions->show_post_title) ? $pluginOptions->show_post_title : '1';
        $sortOrder = isset($pluginOptions->sort_order) ? $pluginOptions->sort_order : 'newest_featured';
        $customCss = isset($pluginOptions->custom_css) ? $pluginOptions->custom_css : '';

        // 头像固定显示 48px，但请求 120px 高清图
        $displaySize = 48;
        $fetchSize = 120;

        // 根据排序配置确定 ORDER BY
        $orderBy = 'created';
        $orderSort = Typecho_Db::SORT_DESC;

        switch ($sortOrder) {
            case 'oldest_featured':
                $orderBy = 'featured_at';
                $orderSort = Typecho_Db::SORT_ASC;
                break;
            case 'newest_comment':
                $orderBy = 'created';
                $orderSort = Typecho_Db::SORT_DESC;
                break;
            case 'oldest_comment':
                $orderBy = 'created';
                $orderSort = Typecho_Db::SORT_ASC;
                break;
            case 'newest_featured':
            default:
                $orderBy = 'featured_at';
                $orderSort = Typecho_Db::SORT_DESC;
                break;
        }

        try {
            $comments = $db->fetchAll($db
                ->select()
                ->from("{$prefix}comments")
                ->where("featured = 1")
                ->order($orderBy, $orderSort)
                ->limit($displayNum)
            );

            // 为每个评论获取文章信息
            foreach ($comments as &$comment) {
                $cid = $comment['cid'];
                $post = $db->fetchRow($db
                    ->select('cid', 'title', 'slug', 'type', 'status')
                    ->from("{$prefix}contents")
                    ->where("cid = ?", $cid)
                );

                if ($post) {
                    $comment['post_title'] = $post['title'];
                    $comment['post_slug'] = $post['slug'];
                    $comment['post_type'] = $post['type'];
                    $comment['post_status'] = $post['status'];
                    $comment['post_url'] = self::getPostPermalink($post['cid'], $post['slug'], $post['type']);
                } else {
                    $comment['post_title'] = '文章已被删除';
                    $comment['post_slug'] = '';
                    $comment['post_type'] = 'post';
                    $comment['post_url'] = '#';
                }
            }
            unset($comment);

        } catch (Exception $e) {
            $comments = array();
        }

        // 构建前端 HTML
        if (empty($comments)) {
            $html = '<div class="featured-comments-empty">暂无精选评论</div>';
        } else {
            $html = self::buildFrontendHtml($comments, $showPostTitle, $displaySize, $fetchSize, $customCss);
        }

        return str_replace('[featured_comments]', $html, $content);
    }

    /**
     * 构建前端 HTML
     */
    private static function buildFrontendHtml($comments, $showPostTitle, $displaySize, $fetchSize, $customCss)
    {
        $options = Typecho_Widget::widget('Widget_Options');
        $defaultAvatar = Typecho_Common::url('usr/plugins/CommentFocus/sun.svg', $options->siteUrl);

        $html = '<style>';
        $html .= '
/* ===== 精选评论插件默认样式 ===== */
.featured-comments {
    --fc-bg: #faf9f7;
    --fc-card-bg: #ffffff;
    --fc-border: #edeae5;
    --fc-text: #3d3d3d;
    --fc-text-secondary: #7a7569;
    --fc-text-muted: #a8a295;
    --fc-accent: #d4a853;
    --fc-accent-soft: #f5efe4;
    --fc-badge-bg: linear-gradient(135deg, #e8b84e 0%, #d4a045 100%);
    --fc-shadow: 0 1px 3px rgba(0,0,0,0.04), 0 4px 16px rgba(0,0,0,0.03);
    --fc-shadow-hover: 0 2px 8px rgba(0,0,0,0.06), 0 8px 28px rgba(0,0,0,0.05);
    --fc-radius: 16px;
    --fc-radius-sm: 12px;

    margin: 0;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, "PingFang SC", "Microsoft YaHei", sans-serif;
    background: var(--fc-bg);
    border-radius: var(--fc-radius);
    padding: 16px 12px;
    border: 1px solid var(--fc-border);
    box-sizing: border-box;
    width: 100%;
}

@media (max-width: 767px) {
    .featured-comments {
        padding: 12px 8px;
        border-radius: var(--fc-radius-sm);
    }
}

.featured-comments-empty {
    text-align: center;
    padding: 48px 20px;
    color: var(--fc-text-muted);
    font-size: 14px;
    letter-spacing: 0.5px;
}

.fc-item {
    background: var(--fc-card-bg);
    border-radius: var(--fc-radius-sm);
    padding: 14px;
    margin-bottom: 10px;
    box-shadow: var(--fc-shadow);
    border: 1px solid var(--fc-border);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
}

.fc-item:last-child {
    margin-bottom: 0;
}

.fc-item::before {
    content: "";
    position: absolute;
    left: 0;
    top: 20px;
    bottom: 20px;
    width: 3px;
    background: var(--fc-accent);
    border-radius: 0 2px 2px 0;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.fc-item:hover {
    box-shadow: var(--fc-shadow-hover);
    transform: translateY(-2px);
}

.fc-item:hover::before {
    opacity: 1;
}

.fc-header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 2px;
}

.fc-avatar {
    flex-shrink: 0;
    align-self: center;
    position: relative;
}

.fc-avatar img {
    border-radius: 50%;
    object-fit: cover;
    display: block;
    width: 40px;
    height: 40px;
    background: var(--fc-accent-soft);
}

.fc-meta {
    flex: 1;
    min-width: 0;
    padding-top: 2px;
}

.fc-author-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    white-space: nowrap;
    gap: 4px;
    margin-bottom: 2px;
}

.fc-name-group {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-shrink: 0;
    min-width: 0;
    overflow: hidden;
}

.fc-author {
    color: var(--fc-text);
    font-size: 14px;
    font-weight: 600;
    line-height: 1.4;
    white-space: nowrap;
    letter-spacing: 0.3px;
}

.fc-time {
    color: var(--fc-text-muted);
    font-size: 12px;
    font-weight: 400;
    flex-shrink: 0;
    white-space: nowrap;
    margin-left: auto;
    padding-left: 8px;
}

.fc-post-title {
    font-size: 13px;
    line-height: 1.5;
    color: var(--fc-text-secondary);
    margin: 0;
}

.fc-post-title a {
    display: inline-block;
    width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: var(--fc-text-secondary);
    text-decoration: none;
    transition: all 0.2s ease;
    font-weight: 400;
}

@media (max-width: 767px) {
    .fc-post-title a {
        max-width: 20ch;
    }
}

@media (min-width: 768px) {
    .fc-post-title a {
        max-width: unset;
    }
}

.fc-post-title a:hover {
    color: var(--fc-accent);
    text-decoration: none;
}

.fc-badge {
    display: inline-flex;
    align-items: center;
    background: var(--fc-badge-bg);
    color: #fff;
    font-size: 10px;
    padding: 1px 8px;
    border-radius: 20px;
    font-weight: 500;
    letter-spacing: 0.5px;
    line-height: 1.6;
    white-space: nowrap;
    vertical-align: middle;
    box-shadow: 0 1px 4px rgba(212,168,83,0.25);
    text-shadow: 0 1px 1px rgba(0,0,0,0.08);
}

.fc-content {
    line-height: 1.8;
    color: var(--fc-text);
    font-size: 15px;
    word-break: break-word;
    padding-left: 50px;
    margin-top: 6px;
}

@media (max-width: 767px) {
    .fc-content {
        padding-left: 0;
        margin-top: 10px;
    }
}

.fc-content p {
    margin: 0 0 8px;
}

.fc-content p:last-child {
    margin-bottom: 0;
}

.fc-content br {
    display: block;
    content: "";
    margin-top: 4px;
}

';

        // 追加用户自定义 CSS
        if (!empty($customCss)) {
            $html .= "\n/* ===== 用户自定义 CSS ===== */\n" . $customCss;
        }

        $html .= '</style>';
        $html .= '<div class="featured-comments">';

        foreach ($comments as $comment) {
            $html .= '<div class="fc-item">';

            // 头部信息区：头像 + 元信息
            $html .= '<div class="fc-header">';

            // 头像（始终显示，无头像时使用 sun.svg 默认图）
            if (!empty($comment['mail'])) {
                $email = trim(strtolower($comment['mail']));
                $hash = md5($email);
                $avatarUrl = "https://cravatar.cn/avatar/{$hash}?s={$fetchSize}&d=" . urlencode($defaultAvatar) . "&r=g";
            } else {
                $avatarUrl = $defaultAvatar;
            }

            $html .= '<div class="fc-avatar">';
            $html .= '<img src="' . $avatarUrl . '" alt="' . htmlspecialchars($comment['author']) . '" width="' . $displaySize . '" height="' . $displaySize . '">';
            $html .= '</div>';

            // 元信息区
            $html .= '<div class="fc-meta">';

            // 第一行：名字 + 精选标签（左）| 时间（右）
            $html .= '<div class="fc-author-row">';
            $html .= '<div class="fc-name-group">';
            $html .= '<span class="fc-author">' . htmlspecialchars($comment['author']) . '</span>';
            $html .= '<span class="fc-badge">精选</span>';
            $html .= '</div>';
            $html .= '<span class="fc-time">' . date('Y-m-d', $comment['created']) . '</span>';
            $html .= '</div>';

            if ($showPostTitle == '1' && !empty($comment['post_title'])) {
                $html .= '<div class="fc-post-title">';
                $html .= '<a href="' . $comment['post_url'] . '" target="_blank">';
                $html .= htmlspecialchars($comment['post_title']);
                $html .= '</a>';
                $html .= '</div>';
            }

            $html .= '</div>'; // end fc-meta
            $html .= '</div>'; // end fc-header

            // 评论内容
            $html .= '<div class="fc-content">';
            $html .= nl2br(htmlspecialchars($comment['text']));
            $html .= '</div>';

            $html .= '</div>'; // end fc-item
        }

        $html .= '</div>'; // end featured-comments

        return $html;
    }
}
