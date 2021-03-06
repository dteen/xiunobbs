<?php

/*
 * Copyright (C) xiuno.com
 */

!defined('FRAMEWORK_PATH') && exit('FRAMEWORK_PATH not defined.');

include BBS_PATH.'control/common_control.class.php';

class post_control extends common_control {
	
	function __construct(&$conf) {
		parent::__construct($conf);
		$this->_checked['bbs'] = ' class="checked"';
		
		// 加载积分策略
		$this->conf += $this->kv->xget('conf_ext');
		
		// 检查IP 屏蔽
		$this->check_ip();
	}
	
	// create ajax
	// 
	public function on_thread() {
		$this->_title[] = '发表帖子';
		$this->_nav[] = '发表帖子';
		
		$this->check_login();
		$this->check_forbidden_group();
		
		$fid =  core::gpc('fid', 'P') ?  intval(core::gpc('fid', 'P')) : intval(core::gpc('fid'));
		if(empty($fid)) {
			list($fid, $forumname) = each($this->conf['forumarr']);
			$forumselect = form::get_select('fid', $this->conf['forumarr'], $fid);
			$this->view->assign('forumselect', $forumselect);
		} else {
			$forumselect = '';
			$this->view->assign('forumselect', $forumselect);
		}
		
		$forum = $this->mcache->read('forum', $fid);
		
		$typeid1 = intval(core::gpc('typeid1', 'R'));
		$typeid2 = intval(core::gpc('typeid2', 'R'));
		$typeid3 = intval(core::gpc('typeid3', 'R'));
		$typeid4 = intval(core::gpc('typeid4', 'R'));
		
		$this->thread_type->check_typeid($typeid1, 1);
		$this->thread_type->check_typeid($typeid2, 2);
		$this->thread_type->check_typeid($typeid3, 3);
		$this->thread_type->check_typeid($typeid4, 4);
		
		$uid = $this->_user['uid'];
		$username = $this->_user['username'];
		$user = $this->user->read($uid);
		
		$this->check_forum_exists($forum);
		$this->check_access($forum, 'thread');
		
		$this->check_user_delete($user);
		
		if(!$this->form_submit()) {
			
			$attachlist = $this->get_attachlist_by_tmp($uid);
			$this->init_editor_attach($attachlist, '0');
		
			$this->view->assign('fid', $fid);
			
			// 初始化 select 控件
			$this->init_type_select($forum, $typeid1, $typeid2, $typeid3, $typeid4);
			
			// hook post_thread_before.php
			$this->view->display('post_thread_ajax.htm');
		} else {
			
			$subject = htmlspecialchars(core::gpc('subject', 'P'));
			
			$message = core::gpc('message', 'P');
			$message = $this->post->html_safe($message);
			
			$thread = $post = $error = array();
		
			// -----------> 添加到 thread
			$thread = array(
				'fid'=>$fid,
				'uid'=>$uid,
				'username'=>$username,
				'subject'=>$subject,
				'dateline'=>$_SERVER['time'],
				'lastpost'=>$_SERVER['time'],
				'lastuid'=>'',
				'lastusername'=>'',
				'views'=>0,
				'posts'=>1,
				'top'=>0,
				'imagenum'=>0,	// 需要最后更新
				'attachnum'=>0,	// 需要最后更新
				'modnum'=>0,	// 评分次数
				'closed'=>0,
				'firstpid'=>0,	// 需要最后更新，也就是最小的pid，冗余存储，提高速度
				'typeid1'=>$typeid1,	//
				'typeid2'=>$typeid2,	//
				'typeid3'=>$typeid3,	//
				'typeid4'=>$typeid4,	//
				'status'=>0,
			);
			
			// hook post_thread_after.php
			
			$error['subject'] = $this->thread->check_subject($thread['subject']);
			$error['message'] = $this->post->check_message($message);
			
			if(!array_filter($error)) {
				$error = array();
				
				// hook post_thread_create_before.php
				
				$tid = $thread['tid'] = $this->thread->create($thread);
				if(!$thread['tid']) {
					$this->message('发帖过程中保存数据错误，请联系管理员。');
				}
				// hook post_thread_create_after.php
				
				$this->thread_views->create(array('tid'=>$tid, 'views'=>0));
				if(isset($this->conf['forumarr'][$fid])) {
					$this->thread_new->create(array('fid'=>$fid, 'tid'=>$tid, 'dateline'=>$_SERVER['time'], 'lastpost'=>$_SERVER['time']));
				}
				
				// -----------> 添加到 post
				
				$page = 1;
				$post = array (
					'fid'=>$fid,
					'tid'=>$thread['tid'],
					'uid'=>$uid,
					'dateline'=>$_SERVER['time'],
					'userip'=>ip2long($_SERVER['ip']),
					'attachnum'=>0,
					'imagenum'=>0,
					'rates'=>0,
					'page'=>$page,
					'username'=>$username,
					'subject'=>'',
					'message'=>$message,
				);
				
				// hook post_thread_post_create_before.php
				$pid = $post['pid'] = $this->post->create($post);
				// hook post_thread_post_create_after.php
				
				// 更新 $attach 上传文件的pid
				$attachnum = $imagenum = 0;
				$aidarr = $this->attach->get_aid_from_tmp($uid);
				foreach($aidarr as $fid_aid) {
					$arr = explode('_', $fid_aid);
					$fid = intval($arr[0]);
					$aid = intval($arr[1]);
					$attach = $this->attach->read($fid, $aid);
					if(empty($attach)) continue;
					if($attach['uid'] != $uid) continue;
					$attach['fid'] = $post['fid'];
					$attach['pid'] = $post['pid'];
					$attach['tid'] = $post['tid'];
					if($attach['isimage'] == 1) {
						$imagenum++;
					} else {
						$attachnum++;
					}
					$this->attach->db_cache_update("attach-fid-$fid-aid-$aid", $attach);
				}
				$this->attach->clear_aid_from_tmp($uid);
				
				// 加入到 thread_type
				$this->thread_type_data->xcreate($fid, $tid, $typeid1, $typeid2, $typeid3, $typeid4);
				
				// 更新 $thread firstpid
				$thread['firstpid'] = $post['pid'];
				$thread['imagenum'] = $imagenum;
				$thread['attachnum'] = $attachnum;
				$this->thread->update($thread);
				
				// 更新 $post
				$post['imagenum'] = $imagenum;
				$post['attachnum'] = $attachnum;
				$this->post->update($post);
				
				// 更新 $user 用户发帖数，积分
				//$user = $this->user->read($uid);
				$user['threads']++;
				$user['posts']++;
				$user['credits'] += $this->conf['credits_policy_thread'];
				$user['golds'] += $this->conf['golds_policy_thread'];
				$groupid = $user['groupid'];
				$user['groupid'] = $this->group->get_groupid_by_credits($user['groupid'], $user['credits']);
				
				// 更新 cookie 如果用户组发生改变，更新用户的 cookie
				if($groupid != $user['groupid']) {
					$this->user->set_login_cookie($user);
				}
				
				// 加入 $mypost
				if(!$forum['accesson']) {
					$this->mypost->create(array('uid'=>$uid, 'fid'=>$fid, 'tid'=>$tid, 'pid'=>$pid));
					$user['myposts']++;
				}
				
				// 更新 user
				$this->user->update($user);
				
				// 更新 $forum 板块的总贴数
				$forum = $this->forum->read($fid);
				$forum['threads']++;
				$forum['posts']++;
				$forum['todayposts']++;
				$forum['lasttid'] = $tid;
				$this->forum->xupdate($forum);
				$this->runtime->xset('posts', '+1');
				$this->runtime->xset('threads', '+1');
				$this->runtime->xset('todayposts', '+1');
				// $this->runtime->xsave();
				
				// $error
				$error['thread'] = $thread;
				
				// hook post_thread_succeed.php
			}
			$this->message($error);
		}
	}
	
	public function on_post() {
		$fid = intval(core::gpc('fid'));
		$tid = intval(core::gpc('tid'));
		$quickpost = intval(core::gpc('quickpost'));
		
		$this->check_login();
		$this->check_forbidden_group();
		
		$uid = $this->_user['uid'];
		$username = $this->_user['username'];
		$user = $this->user->read($uid);
		$this->check_user_delete($user);
		
		$group = $this->group->read($user['groupid']);
		
		// 帖子存在检查
		$thread = $this->thread->read($fid, $tid);
		$this->check_thread_exists($thread);
		
		// 帖子回复数不能超过 10000
		if($thread['posts'] > 10000) {
			$this->message('该帖子回复数已经达到10000，不能再回复了，再起话题吧！');
		}
		
		// 板块权限检查
		$forum = $this->mcache->read('forum', $fid);
		$this->check_forum_exists($forum);
		$this->check_access($forum, 'post');
		
		if(!$this->form_submit()) {
			
			$pid = intval(core::gpc('pid'));
			if($pid) {
				$post = $this->post->read($fid, $pid);
				$this->check_post_exists($post);
				
				// 引用前两百个字
				$message = $this->quote_message($post);
			} else {
				$message = '';
			}
			
			// 附件相关
			$attachlist = $this->get_attachlist_by_tmp($uid);
			$this->init_editor_attach($attachlist, '00');
			
			$this->view->assign('fid', $fid);
			$this->view->assign('tid', $tid);
			$this->view->assign('thread', $thread);
			$this->view->assign('message', $message);
			$this->view->assign('forum', $forum);
			// hook post_post_before.php
			$this->view->display('post_post_ajax.htm');
		} else {
			
			// 引用某帖
			$pid = intval(core::gpc('pid'));
			
			$post = $error = array();
			$subject = htmlspecialchars(core::gpc('subject', 'P')); // 废弃
			$message = core::gpc('message', 'P');
			
			// 快速发帖。
			if($quickpost) {
				$message = misc::html_space($message);
				$message = preg_replace('#(\w+://[^\s<]+)#', '<a href="\\1" target="_blank">\\1</a>', $message);
				$message = $this->post->html_safe($message);
			} else {
				$message = $this->post->html_safe($message);
			}
			
			// -----------> 添加到 post
			$attachnum = $imagenum = 0;
			$page = 1;
			$page = ceil(($thread['posts'] + 1) / $this->conf['pagesize']);
			$post = array (
				'tid'=>$thread['tid'],
				'fid'=>$fid,
				'uid'=>$uid,
				'dateline'=>$_SERVER['time'],
				'userip'=>ip2long($_SERVER['ip']),
				'attachnum'=>0,
				'imagenum'=>0,
				'rates'=>0,
				'page'=>$page,
				'username'=>$username,
				'subject'=>'',
				'message'=>$message,
			);
			
			$error['message'] = $this->post->check_message($post['message']);
			
			// hook post_post_after.php
			if(!array_filter($error)) {
				$error = array();
				//$error['page'] = $page;
				$error['post'] = $post;
				$error['post']['posts'] = $thread['posts'] + 1;
				
				// hook post_post_post_create_before.php
				$pid = $post['pid'] = $this->post->create($post);
				// hook post_post_post_create_after.php
				
				// 更新 $attach 上传文件的pid
				$aidarr = $this->attach->get_aid_from_tmp($uid);
				foreach($aidarr as $fid_aid) {
					$arr = explode('_', $fid_aid);
					$fid = intval($arr[0]);
					$aid = intval($arr[1]);
					$attach = $this->attach->read($fid, $aid);
					if(empty($attach)) continue;
					if($attach['uid'] != $uid) continue;
					$attach['fid'] = $post['fid'];
					$attach['pid'] = $post['pid'];
					$attach['tid'] = $post['tid'];
					if($attach['isimage'] == 1) {
						$imagenum++;
					} else {
						$attachnum++;
					}
					$this->attach->db_cache_update("attach-fid-$fid-aid-$aid", $attach);
				}
				$this->attach->clear_aid_from_tmp($uid);
				
				// 更新 $post
				$post['attachnum'] = $attachnum;
				$post['imagenum'] = $imagenum;
				$this->post->update($post);
				
				// 更新 $user 用户发帖数，积分
				$user = $this->user->read($uid);
				$user['posts']++;
				$user['credits'] += $this->conf['credits_policy_post'];
				$user['golds'] += $this->conf['golds_policy_post'];
				$groupid = $user['groupid'];
				$user['groupid'] = $this->group->get_groupid_by_credits($user['groupid'], $user['credits']);
				
				// 更新 cookie 如果用户组发生改变，更新用户的 cookie
				if($groupid != $user['groupid']) {
					$this->user->set_login_cookie($user);
				}
				
				// 加入 $mypost
				if(!$this->mypost->have_tid($uid, $fid, $tid) && !$forum['accesson']) {
					$this->mypost->create(array('uid'=>$uid, 'fid'=>$fid, 'tid'=>$tid, 'pid'=>$pid));
					$user['myposts']++;
				}
				
				// 更新 $user 
				$this->user->update($user);
					
				// 更新 $forum 板块的总贴数
				$forum = $this->forum->read($fid);
				$forum['posts']++;
				$forum['todayposts']++;
				$forum['lasttid'] = $thread['tid'];
				$this->forum->xupdate($forum);
				
				// 今日总的发帖数
				$this->runtime->xset('posts', '+1');
				$this->runtime->xset('todayposts', '+1');
				
				// 更新 $thread
				$thread['posts']++;
				$thread['lastuid'] = $uid;
				$thread['lastpost'] = $_SERVER['time'];
				$thread['lastusername'] = $username;
				$this->thread->update($thread);
				
				// 斑竹回复的话， 短消息通知楼主，有人回帖，每个主题前10名用户，引用回复也发送，高级别会员回复通知。
				$tuser = $this->user->read($thread['uid']);
				if($this->_user['uid'] != $thread['uid'] && $this->_user['groupid'] <= 5) {
					$pmsubject = utf8::substr($thread['subject'], 0, 16);
					$pmmessage = "【{$this->_user['username']}】回复了您的主题：<a href=\"?thread-index-fid-$fid-tid-$tid.htm\" target=\"_blank\">【{$pmsubject}】</a>。";
					$this->pm->system_send($thread['uid'], $thread['username'], $pmmessage);
				}
				
				// hook post_post_succeed.php
			}
			$this->message($error);
		}
	}
	
	// 修改 ajax
	public function on_update() {
		$this->_title[] = '修改帖子';
		$this->_nav[] = '修改帖子';
		
		$this->check_login();
		$this->check_forbidden_group();
		
		$fid = intval(core::gpc('fid'));
		$pid = intval(core::gpc('pid'));
		
		$uid = $this->_user['uid'];
		$username = $this->_user['username'];
		$user = $this->user->read($uid);
		$this->check_user_delete($user);
		
		// 板块权限检查
		$forum = $this->mcache->read('forum', $fid);
		$this->check_forum_exists($forum);
		$this->check_access($forum, 'post');
		
		$post = $this->post->read($fid, $pid);
		$this->check_post_exists($post);
		$tid = intval($post['tid']);
		
		$thread = $this->thread->read($fid, $tid);
		$this->check_thread_exists($thread);
		
		$ismod = $this->is_mod($forum, $this->_user);
		// 编辑权限检查：管理员，版主，可以编辑
		if($post['uid'] != $this->_user['uid']) {
			$this->check_access($forum, 'update');
		}
		
		// 过期不能编辑
		// post_update_expiry
		$this->conf += $this->kv->xget('conf_ext');
		if(!$ismod && $this->conf['post_update_expiry'] && $_SERVER['time'] - $post['dateline'] > $this->conf['post_update_expiry']) {
			$time = ceil($this->conf['post_update_expiry'] / 60);
			$this->message('您不能再继续修改该贴，已经超出了最大修改时间: (<b>'.$time.'分钟</b>)。', 0);
		}
		
		// 是否为首贴
		$isfirst = $thread['firstpid'] == $pid;
		
		$input = $error = array();
		if(!$this->form_submit()) {
			$post['message_html'] = htmlspecialchars($post['message']);;
			
			// 附件相关
			$attachlist = $this->attach->get_list_by_fid_pid($fid, $pid, 0);
			$this->init_editor_attach($attachlist, $pid);
			
			if($isfirst) {
				$this->init_type_select($forum, $thread['typeid1'], $thread['typeid2'], $thread['typeid3'], $thread['typeid4']);
			}
			
			$this->view->assign('isfirst', $isfirst);
			$this->view->assign('fid', $fid);
			$this->view->assign('tid', $tid);
			$this->view->assign('pid', $pid);
			$this->view->assign('post', $post);
			$this->view->assign('thread', $thread);
			$this->view->assign('forum', $forum);
			$this->view->assign('input', $input);
			$this->view->assign('error', $error);
			// hook post_update_before.php
			$this->view->display('post_update_ajax.htm');
			//$this->view->display('__post_update.htm');
		} else {
			
			$subject = htmlspecialchars(core::gpc('subject', 'P'));
			$message = $this->post->html_safe(core::gpc('message', 'P'));
			
			// 更新数据
			if($isfirst) {
				
				// 更新 threadtype
				$typeid1 = intval(core::gpc('typeid1', 'P'));	// 检查合法范围
				$typeid2 = intval(core::gpc('typeid2', 'P'));	// 检查合法范围
				$typeid3 = intval(core::gpc('typeid3', 'P'));	// 检查合法范围
				$typeid4 = intval(core::gpc('typeid4', 'P'));	// 检查合法范围
				$this->thread_type->check_typeid($typeid1, 1);
				$this->thread_type->check_typeid($typeid2, 2);
				$this->thread_type->check_typeid($typeid3, 3);
				$this->thread_type->check_typeid($typeid4, 4);
				$this->thread_type_data->xupdate($fid, $tid, $typeid1, $typeid2, $typeid3, $typeid4);
				
				$thread['typeid1'] = $typeid1;
				$thread['typeid2'] = $typeid2;
				$thread['typeid3'] = $typeid3;
				$thread['typeid4'] = $typeid4;
				$thread['subject'] = $subject;
				$error['subject'] = $this->thread->check_subject($thread['subject']);
			}
			$post['message'] = $message;
			$error['message'] = $this->post->check_message($post['message']);
			
			// hook post_update_after.php
			
			// 如果检测没有错误，则更新
			if(!array_filter($error)) {
				$error = array();
				
				// 更新 $attach 上传文件的pid
				$attachnum = $imagenum = 0;
				$aidarr = $this->attach->get_aid_from_tmp($uid);
				foreach($aidarr as $fid_aid) {
					$arr = explode('_', $fid_aid);
					$fid = intval($arr[0]);
					$aid = intval($arr[1]);
					
					$attach = $this->attach->read($fid, $aid);
					if(empty($attach)) continue;
					if($attach['uid'] != $uid) continue;
					$attach['fid'] = $post['fid'];
					$attach['pid'] = $post['pid'];
					$attach['tid'] = $post['tid'];
					if($attach['isimage'] == 1) {
						$imagenum++;
					} else {
						$attachnum++;
					}
					// 修改主键
					$this->attach->db_cache_update("attach-fid-$fid-aid-$aid", $attach);
				}
				$this->attach->clear_aid_from_tmp($uid);
				
				// 删除没有被引用的图片
				$attachlist = $this->attach->get_list_by_fid_pid($fid, $pid, 1);
				$rebuild_cover = 0;
				foreach($attachlist as $attach) {
					$url = $this->conf['upload_url'].$attach['filename'];
					if($attach['filename'] && strpos($post['message'], $attach['filename']) === FALSE) {
						// 删除没有被引用的附件，有点粗暴，可以理解为 word 的编辑方式，删除的图片需要重新上传。
						$this->attach->unlink($attach);
						$this->attach->delete($attach['fid'], $attach['aid']);
						$imagenum--;
						//var_dump('deleted: '.$attach['filename'].', imagenum:'.$imagenum);
					} else {
						//var_dump($attach['filename']);
					}
				}
				
				//var_dump($post['imagenum'].'-'.$imagenum);
				$post['imagenum'] += $imagenum;
				$post['attachnum'] += $attachnum;

				// 如果为主题帖，则更新附件个数
				if($isfirst) {
					$thread['imagenum'] += $imagenum;
					$thread['attachnum'] += $attachnum;
				}
				// hook post_update_thread_update_before.php
				$this->thread->update($thread);
				$this->post->update($post);
				// hook post_update_thread_update_after.php
				
				$this->forum->xupdate($forum);
				
				// hook post_update_succeed.php
				$this->message('更新成功！');
			}
			$this->message($error);
		}
	}
	
	// tpdo: 删除帖子，删除主题, todayposts 未更新
	public function on_delete() {
		$this->_title[] = '删除帖子';
		$this->_nav[] = '删除帖子';
		
		$this->check_login();
		$this->check_forbidden_group();
		
		$pid = intval(core::gpc('pid'));
		$fid = intval(core::gpc('fid'));
		
		$uid = $this->_user['uid'];
		$username = $this->_user['username'];
		$user = $this->user->read($uid);
		$this->check_user_delete($user);
		
		// 板块权限检查
		$forum = $this->mcache->read('forum', $fid);
		$this->check_forum_exists($forum);
		$this->check_access($forum, 'thread');
		
		$post = $this->post->read($fid, $pid);
		$this->check_post_exists($post);
		$tid = $post['tid'];
		
		$thread = $this->thread->read($fid, $tid);
		$this->check_thread_exists($thread);
		
		// 编辑权限检查：管理员，版主，可以编辑
		if($post['uid'] != $this->_user['uid']) {
			$this->check_access($forum, 'delete');
		}
		
		$isfirst = $thread['firstpid'] == $pid;
		
		if($isfirst) {
			
			// hook post_delete_post_before.php
			$this->thread->xdelete($fid, $tid, TRUE);	// 删除 $postlist, 更新 $forum $userlist
			// hook post_delete_post_after.php
			
			$forum['posts'] -= $thread['posts'];
			$forum['threads']--;
			$this->forum->xupdate($forum);
			
			$this->location("?forum-index-fid-$fid.htm");
			
		} else {
			
			// hook post_delete_thread_before.php
			$this->post->xdelete($fid, $pid, TRUE);
			// hook post_delete_thread_after.php
			
			$forum['posts']--;
			$this->forum->xupdate($forum);
			
			// 重建页数
			$this->post->rebuild_page($fid, $tid, $pid, $post['page']);
			
			$this->location("?thread-index-fid-$fid-tid-$tid-page-$post[page].htm");
		}
	}
	
	
	public function on_typeselect() {
		$fid = intval(core::gpc('fid'));
		$forum = $this->mcache->read('forum', $fid);
		$this->check_forum_exists($forum);
		$this->check_forum_access($forum, 'read', $message);
		$typeselects = $this->init_type_select($forum);
		$this->message($typeselects, 1);
	}

	private function get_attachlist_by_tmp($uid) {
		$aids = $this->kv->get("upload_{$uid}_fid_aids.tmp");
		$aidarr = $aids ? explode(' ', $aids) : array();
		$attachlist = array();
		foreach($aidarr as $fid_aid) {
			list($fid, $aid) = explode('_', $fid_aid);
			$attach = $this->attach->read($fid, $aid);
			if($attach) {
				$this->attach->format($attach);
				$attachlist[$aid] = $attach;
			}
		}
		return $attachlist;
	}
		
	private function init_editor_attach($attachlist, $pid) {
		$attachnum = count($attachlist);
		$this->view->assign('attachlist', $attachlist);
		$this->view->assign('attachnum', $attachnum);
		$upload_max_filesize = $this->attach->get_upload_max_filesize();
		$this->view->assign('upload_max_filesize', $upload_max_filesize);
		$filetyps = core::json_encode($this->attach->filetypes);
		$this->view->assign('filetyps', $filetyps);
		$this->view->assign_value('pid', $pid);// 给编辑器附件列表使用
	}

	// copy from post_control.class.php
	private function init_type_select($forum, $typeid1 = 0, $typeid2 = 0, $typeid3 = 0, $typeid4 = 0) {
		$arradd1 = !empty($forum['typecates'][1]) ? array('0'=>$forum['typecates'][1].'▼') : array();
		$arradd2 = !empty($forum['typecates'][2]) ? array('0'=>$forum['typecates'][2].'▼') : array();
		$arradd3 = !empty($forum['typecates'][3]) ? array('0'=>$forum['typecates'][3].'▼') : array();
		$arradd4 = !empty($forum['typecates'][4]) ? array('0'=>$forum['typecates'][4].'▼') : array();
		$typearr1 = empty($forum['types'][1]) ? array() : $arradd1 + (array)$forum['types'][1];
		$typearr2 = empty($forum['types'][2]) ? array() : $arradd2 + (array)$forum['types'][2];
		$typearr3 = empty($forum['types'][3]) ? array() : $arradd3 + (array)$forum['types'][3];
		$typearr4 = empty($forum['types'][4]) ? array() : $arradd4 + (array)$forum['types'][4];
		$typeselect1 = $typearr1 && !empty($forum['types'][1]) ? form::get_select('typeid1', $typearr1, $typeid1, '') : '';
		$typeselect2 = $typearr2 && !empty($forum['types'][2]) ? form::get_select('typeid2', $typearr2, $typeid2, '') : '';
		$typeselect3 = $typearr3 && !empty($forum['types'][3]) ? form::get_select('typeid3', $typearr3, $typeid3, '') : '';
		$typeselect4 = $typearr4 && !empty($forum['types'][4]) ? form::get_select('typeid4', $typearr4, $typeid4, '') : '';
		$this->view->assign('typeselect1', $typeselect1);
		$this->view->assign('typeselect2', $typeselect2);
		$this->view->assign('typeselect3', $typeselect3);
		$this->view->assign('typeselect4', $typeselect4);
		return array('typeselect1'=>$typeselect1, 'typeselect2'=>$typeselect2, 'typeselect3'=>$typeselect3, 'typeselect4'=>$typeselect4);
	}
	
	private function quote_message($post) {
		$s = $post['message'];
		$s = str_ireplace(array('<br>', '<br />', '<br  />'), "\n", $s);
		$s = preg_replace('#<div\s*class=\"quote\">.*?</div>#ism', "", $s); // 避免引用嵌套！
		$s = strip_tags($s);
		$s = preg_replace('#[\r\n]{2,999}#', "<br />", $s);
		$s = utf8::substr($s, 0, 200);
		$s = "<div class=\"quote\"><span class=\"grey\">引用 $post[username]：</span><p>$s</p></div><br /><br />";
		return $s;
	}
	//hook post_control_after.php
}

?>