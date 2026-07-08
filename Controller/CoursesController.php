<?php
/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2021 iroha Soft, Inc. (https://irohasoft.jp)
 * @link          https://irohaboard.irohasoft.jp
 * @license       https://www.gnu.org/licenses/gpl-3.0.en.html GPL License
 */

App::uses('AppController', 'Controller');

/**
 * Courses Controller
 * https://book.cakephp.org/2/ja/controllers.html
 */
class CoursesController extends AppController
{
	/**
	 * 使用するコンポーネント
	 * https://book.cakephp.org/2/ja/core-libraries/toc-components.html
	 */
	public $components = [
		'Security' => [
			'csrfUseOnce' => false,
			'validatePost' => false,
		],
	];

	/**
	 * コース一覧を表示
	 */
	public function admin_index()
	{
		// 不要なリレーションを解除
		$this->Course->recursive = 0;
		
		$courses = $this->Course->find()
			->order('Course.sort_no asc')
			->all();
		$this->set(compact('courses'));
	}

	/**
	 * コースの追加
	 */
	public function admin_add()
	{
		$this->admin_edit();
		$this->render('admin_edit');
	}

	/**
	 * コースの編集
	 * @param int $course_id コースID
	 */
	public function admin_edit($course_id = null)
	{
		if($this->isEditPage() && !$this->Course->exists($course_id))
		{
			throw new NotFoundException(__('Invalid course'));
		}
		
		if($this->request->is(['post', 'put']))
		{
			if(Configure::read('demo_mode'))
				return;
			
			// 作成者を設定
			$this->request->data['Course']['user_id'] = $this->readAuthUser('id');
			
			if($this->Course->save($this->request->data))
			{
				$this->Flash->success(__('コースが保存されました'));
				return $this->redirect(['action' => 'index']);
			}
			else
			{
				$this->Flash->error(__('The course could not be saved. Please, try again.'));
			}
		}
		else
		{
			$this->request->data = $this->Course->get($course_id);
		}
	}

	/**
	 * コースの削除
	 * @param int $course_id コースID
	 */
	public function admin_delete($course_id = null)
	{
		if(Configure::read('demo_mode'))
			return;
		
		$this->Course->id = $course_id;
		if(!$this->Course->exists())
		{
			throw new NotFoundException(__('Invalid course'));
		}

		$this->request->allowMethod('post', 'delete');

		$this->Course->deleteCourse($course_id);

		$this->Flash->success(__('コースが削除されました'));

		return $this->redirect(['action' => 'index']);
	}

	/**
	 * Ajax によるコースの並び替え
	 *
	 * @return string 実行結果
	 */
	public function admin_order()
	{
		$this->autoRender = FALSE;
		if($this->request->is('ajax'))
		{
			$this->Course->setOrder($this->data['id_list']);
			return "OK";
		}
	}

	/**
	 * コースのコピー
	 * @param int $course_id コピーするコースのID
	 */
	public function admin_copy($course_id)
	{
		$this->request->allowMethod('post');
		
		// コピー元のコース情報を抽出(Course, Content)
		$data = $this->Course->get($course_id);
		// コピー先コースのコースidを設定
		$row  = $this->Course->find()
			->select(['MAX(Course.id) as max_id'])
			->first();		
		$new_course_id = $row[0]['max_id'] + 1;
		// コピー先のコース情報を設定
		$data['Course']['id'] = $new_course_id;
		$data['Course']['created'] = null;
		$data['Course']['modified'] = null;
		$data['Course']['opened'] = null;
		$data['Course']['deleted'] = null;
		$data['Course']['title'] .= 'の複製';

		// コピー先コースに登録する先頭のコンテンツidを設定
		$row = $this->fetchTable('Contents')->find()
			->select(['MAX(Contents.id) as max_id'])
			->first();
		$new_content_id = $row[0]['max_id'] + 1;
		// コピー先コースにコンテンツを登録
		foreach($data['Content'] as $content_for_copy)
		{
			$source_id = $content_for_copy['id'];
			$content_for_copy['id']			= $new_content_id;
			$content_for_copy['created']	= null;
			$content_for_copy['modified']	= null;
			$content_for_copy['course_id'] = $new_course_id;

			$this->fetchTable('Contents')->validate = null;			
			$this->fetchTable('Contents')->create($content_for_copy);
			$this->fetchTable('Contents')->save();

			// テストコンテンツの場合、テスト問題をコピー
			if ($content_for_copy['kind'] == 'test')
			{
				// テスト問題を抽出
				$contentsQuestions = $this->fetchTable('ContentsQuestion')->find()
					->where(['content_id' => $source_id])
					->order('ContentsQuestion.sort_no asc')
					->all();
		
				foreach($contentsQuestions as $contentsQuestion)
				{
					$contentsQuestion['ContentsQuestion']['id']			= null;
					$contentsQuestion['ContentsQuestion']['created']	= null;
					$contentsQuestion['ContentsQuestion']['modified']	= null;
					$contentsQuestion['ContentsQuestion']['content_id']	= $new_content_id;
					$contentsQuestion['ContentsQuestion']['sort_no']	= 0;
			
					$this->fetchTable('ContentsQuestion')->validate = null;			
					$this->fetchTable('ContentsQuestion')->create($contentsQuestion);
					$this->fetchTable('ContentsQuestion')->save();
				}
			}
			$new_content_id++;
		}
		$this->Course->save($data['Course']);

		// コンテンツに関連するファイル類をコピー
		$source_folder_path = ROOT.DS.APP_DIR.DS.'files'.DS.'course_'.$course_id;
		$destination_folder_path = ROOT.DS.APP_DIR.DS.'files'.DS.'course_'.$new_course_id;
		if (file_exists($source_folder_path)) {
			$copy_cmd = 'xcopy ' . $source_folder_path . ' ' . $destination_folder_path . ' /Y /I';
			exec($copy_cmd, $out_mes, $return);
			if ($return != 0){ //0 or それ以外
				$this->Flash->success(__('ファイルの複写が失敗しました。'));
			}
		}

		$this->Flash->success(__('コースの複製が完了しました。'));
		
		return $this->redirect(['action' => 'index']);
	}

}
