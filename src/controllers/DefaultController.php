<?php

namespace bs\dbManager\controllers;

use Yii;
use yii\data\ArrayDataProvider;
use yii\filters\VerbFilter;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;
use yii\helpers\StringHelper;
use yii\web\Controller;
use bs\dbManager\models\Dump;
use bs\dbManager\models\Restore;
use Symfony\Component\Process\Process;

/**
 * Default controller.
 */
class DefaultController extends Controller
{
    /**
     * @return Module
     */
    public function getModule()
    {
        return $this->module;
    }

    /**
     * @return array
     */
    public function behaviors()
    {
        return [
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'create' => ['post'],
                    'delete' => ['post'],
                    'delete-all' => ['post'],
                    'restore' => ['get', 'post'],
                    '*' => ['get'],
                ],
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function actionIndex()
    {
        $dataArray = $this->prepareFileData();
        $dbList = $this->getModule()->dbList;
        $model = new Dump($dbList, $this->getModule()->customDumpOptions);
        $dataProvider = new ArrayDataProvider([
            'allModels' => $dataArray,
            'pagination' => [
                'pageSize' => 30,
            ],
        ]);
        $activePids = $this->checkActivePids();

        return $this->render('index', [
            'dataProvider' => $dataProvider,
            'model' => $model,
            'dbList' => $dbList,
            'activePids' => $activePids,
        ]);
    }

    /**
     * @inheritdoc
     */
    public function actionCreate()
    {
        $model = new Dump($this->getModule()->dbList, $this->getModule()->customDumpOptions);
        if ($model->load(Yii::$app->request->post()) && $model->validate()) {
            $dbInfo = $this->getModule()->getDbInfo($model->db);
            $dumpOptions = $model->makeDumpOptions();
            $manager = $this->getModule()->createManager($dbInfo);
            $dumpPath = $manager->makePath($this->getModule()->path, $dbInfo, $dumpOptions);

            $dumpPath = $this->changeFileName($dumpPath, $model);

            $dumpCommand = $manager->makeDumpCommand($dumpPath, $dbInfo, $dumpOptions);
            Yii::trace(compact('dumpCommand', 'dumpPath', 'dumpOptions'), get_called_class());
            if ($model->runInBackground) {
                $this->runProcessAsync($dumpCommand);
            } else {
                $this->runProcess($dumpCommand);
            }
        } else {
            Yii::$app->session->setFlash('error', Yii::t('dbManager', 'Dump request invalid.') . '<br>' . Html::errorSummary($model));
        }

        return $this->redirect(['index']);
    }

    /**
     * @inheritdoc
     */
    public function actionDownload($id)
    {
        $dumpPath = $this->getModule()->path . StringHelper::basename(ArrayHelper::getValue($this->getModule()->getFileList(), $id));

        return Yii::$app->response->sendFile($dumpPath);
    }

    /**
     * @inheritdoc
     */
    public function actionRestore($id)
    {
        $dumpFile = $this->getModule()->path . StringHelper::basename(ArrayHelper::getValue($this->getModule()->getFileList(), $id));
        $model = new Restore($this->getModule()->dbList, $this->getModule()->customRestoreOptions);
        if (Yii::$app->request->isPost) {
            if ($model->load(Yii::$app->request->post()) && $model->validate()) {
                $dbInfo = $this->getModule()->getDbInfo($model->db);
                $restoreOptions = $model->makeRestoreOptions();
                $manager = $this->getModule()->createManager($dbInfo);
                $restoreCommand = $manager->makeRestoreCommand($dumpFile, $dbInfo, $restoreOptions);
                Yii::trace(compact('restoreCommand', 'dumpFile', 'restoreOptions'), get_called_class());
                if ($model->runInBackground) {
                    $this->runProcessAsync($restoreCommand, true);
                } else {
                    $this->runProcess($restoreCommand, true);
                }

                return $this->redirect(['index']);
            }
        }

        return $this->render('restore', [
            'model' => $model,
            'file' => $dumpFile,
            'id' => $id,
        ]);
    }

     /**
     * 如果用戶有填寫了文件名，則修改
     * @param unknown $dumpPath
     * @param unknown $model
     */
    public function changeFileName($dumpPath, $model)
    {
        $filename_remark = trim($model->filename_remark);
        if( !empty($filename_remark) ){
            $old_filename = substr($dumpPath, strrpos($dumpPath, '/')+1);
            $old_dir = substr($dumpPath, 0, strrpos($dumpPath, '/')+1);
            $old_filename_ext = substr($old_filename, strpos($old_filename, '.'));
            
            $filename_remark = preg_replace("/\ |\/|\~|\!|\@|\#|\\$|\%|\^|、|。|，|、|\&|\*|\(|\)|\_|\+|\{|\}|\:|\<|\>|\?|\[|\]|\,|\.|\/|\;|\'|\`|\-|\=|\\\|\|/",'',$filename_remark);
            $tenantId = $this->getModule()->getMultiTenancyId();
            $filename_remark = $filename_remark.'_'.date('ymdHis') . ($tenantId?$tenantId:'');
            $dumpPath = $old_dir.$filename_remark.$old_filename_ext;
        }
        return $dumpPath;
    }

    /**
     * @inheritdoc
     */
    public function actionStorage($id)
    {
        if (Yii::$app->has('backupStorage')) {
            $dumpname = StringHelper::basename(ArrayHelper::getValue($this->getModule()->getFileList(), $id));
            $dumpPath = $this->getModule()->path . $dumpname;
            $exists = Yii::$app->backupStorage->has($dumpname);
            if ($exists) {
                Yii::$app->backupStorage->delete($dumpname);
                Yii::$app->session->setFlash('success', Yii::t('dbManager', 'Dump deleted from storage.'));
            } else {
                $stream = fopen($dumpPath, 'r+');
                Yii::$app->backupStorage->writeStream($dumpname, $stream);
                Yii::$app->session->setFlash('success', Yii::t('dbManager', 'Dump uploaded to storage.'));
            }
        }

        return $this->redirect(['index']);
    }

    /**
     * @inheritdoc
     */
    public function actionDelete($id)
    {
        $dumpFile = $this->getModule()->path . StringHelper::basename(ArrayHelper::getValue($this->getModule()->getFileList(), $id));
        if (unlink($dumpFile)) {
            Yii::$app->session->setFlash('success', Yii::t('dbManager', 'Dump deleted successfully.'));
        } else {
            Yii::$app->session->setFlash('error', Yii::t('dbManager', 'Error deleting dump.'));
        }

        return $this->redirect(['index']);
    }

    /**
     * @inheritdoc
     */
    public function actionDeleteAll()
    {
        if (!empty($this->getModule()->getFileList())) {
            $fail = [];
            foreach ($this->getModule()->getFileList() as $file) {
                if (!unlink($file)) {
                    $fail[] = $file;
                }
            }
            if (empty($fail)) {
                Yii::$app->session->setFlash('success', Yii::t('dbManager', 'All dumps successfully removed.'));
            } else {
                Yii::$app->session->setFlash('error', Yii::t('dbManager', 'Error deleting dumps.'));
            }
        }

        return $this->redirect(['index']);
    }

    /**
     * @param $command
     * @param bool $isRestore
     */
    protected function runProcess($command, $isRestore = false)
    {
        $process = new Process($command);
        $process->setTimeout($this->getModule()->timeout);
        $process->run();
        if ($process->isSuccessful()) {
            $msg = (!$isRestore) ? Yii::t('dbManager', 'Dump successfully created.') : Yii::t('dbManager', 'Dump successfully restored.');
            Yii::$app->session->addFlash('success', $msg);
        } else {
            $msg = (!$isRestore) ? Yii::t('dbManager', 'Dump failed.') : Yii::t('dbManager', 'Restore failed.');
            Yii::$app->session->addFlash('error', $msg . '<br>' . 'Command - ' . $command . '<br>' . $process->getOutput() . $process->getErrorOutput());
            Yii::error($msg . PHP_EOL . 'Command - ' . $command . PHP_EOL . $process->getOutput() . PHP_EOL . $process->getErrorOutput());
        }
    }

    /**
     * @param $command
     * @param bool $isRestore
     */
    protected function runProcessAsync($command, $isRestore = false)
    {
        $process = new Process($command);
        $process->setTimeout($this->getModule()->timeout);
        $process->start();
        $pid = $process->getPid();
        $activePids = Yii::$app->session->get('backupPids', []);
        if (!$process->isRunning()) {
            if ($process->isSuccessful()) {
                $msg = (!$isRestore) ? Yii::t('dbManager', 'Dump successfully created.') : Yii::t('dbManager', 'Dump successfully restored.');
                Yii::$app->session->addFlash('success', $msg);
            } else {
                $msg = (!$isRestore) ? Yii::t('dbManager', 'Dump failed.') : Yii::t('dbManager', 'Restore failed.');
                Yii::$app->session->addFlash('error', $msg . '<br>' . 'Command - ' . $command . '<br>' . $process->getOutput() . $process->getErrorOutput());
                Yii::error($msg . PHP_EOL . 'Command - ' . $command . PHP_EOL . $process->getOutput() . PHP_EOL . $process->getErrorOutput());
            }
        } else {
            $activePids[$pid] = $command;
            Yii::$app->session->set('backupPids', $activePids);
            Yii::$app->session->addFlash('info', Yii::t('dbManager', 'Process running with pid={pid}', ['pid' => $pid]) . '<br>' . $command);
        }
    }

    /**
     * @return array
     */
    protected function checkActivePids()
    {
        $activePids = Yii::$app->session->get('backupPids', []);
        $newActivePids = [];
        if (!empty($activePids)) {
            foreach ($activePids as $pid => $cmd) {
                $process = new Process('ps -p ' . $pid);
                $process->setTimeout($this->getModule()->timeout);
                $process->run();
                if (!$process->isSuccessful()) {
                    Yii::$app->session->addFlash('success',
                        Yii::t('dbManager', 'Process complete!') . '<br> PID=' . $pid . ' ' . $cmd);
                } else {
                    $newActivePids[$pid] = $cmd;
                }
            }
        }
        Yii::$app->session->set('backupPids', $newActivePids);

        return $newActivePids;
    }

    /**
     * @return array
     */
    protected function prepareFileData()
    {
        $dataArray = [];
        foreach ($this->getModule()->getFileList() as $id => $file) {
            $dataArray[] = [
                'id' => $id,
                'type' => pathinfo($file, PATHINFO_EXTENSION),
                'name' => StringHelper::basename($file),
                'size' => Yii::$app->formatter->asSize(filesize($file)),
                'create_at' => Yii::$app->formatter->asDatetime(filectime($file)),
            ];
        }
        ArrayHelper::multisort($dataArray, ['create_at'], [SORT_DESC]);

        return $dataArray ?: [];
    }
}
