<?php

use yii\bootstrap5\ActiveForm;
use yii\bootstrap5\Html;
use yii\grid\GridView;
use bs\dbManager\models\BaseDumpManager;

/* @var $this yii\web\View */
/* @var array $dbList */
/* @var array $activePids */
/* @var \bs\dbManager\models\Dump $model */
/* @var $dataProvider yii\data\ArrayDataProvider */

$this->title = Yii::t('dbManager', 'DB manager');
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="dbManager-default-index">

    <div class="well">
        <?php $form = ActiveForm::begin([
            'action' => ['create'],
            'method' => 'post',
            'layout' => 'inline',
        ]) ?>


        <div class="d-none">
            <?= $form->field($model, 'db')->dropDownList(array_combine($dbList, $dbList)) ?>
        </div>

        <div class="row">

            <div class="col-lg-3 col-sm-4 ">
                <?= $form->field($model, 'filename_remark')->textInput(['placeholder' => Yii::t('dbManager', 'File Name')]); ?>
            </div>
            <div class="col-lg-3 col-sm-4">
                <?= Html::submitButton(Yii::t('dbManager', 'Create dump'), ['class' => 'btn btn-success']) ?>
            </div>

            <div class="col-lg-6 col-sm-4">
                <div class="d-flex justify-content-end">
                    <?= Html::a(
                        Yii::t('dbManager', 'Delete all'),
                        ['delete-all'],
                        [
                            'class' => 'btn btn-danger',
                            'data-method' => 'post',
                            'data-confirm' => Yii::t('dbManager', 'Are you sure?'),
                        ]
                    ) ?>
                </div>
            </div>
        </div>



        <?php //echo $form->field($model, 'isArchive')->checkbox() 
        ?>

        <?php //echo $form->field($model, 'schemaOnly')->checkbox() 
        ?>

        <?php 
        // if (!BaseDumpManager::isWindows()) {
        //     echo $form->field($model, 'runInBackground')->checkbox();
        // } 
        ?>

        <?php if ($model->hasPresets()) : ?>
            <?= $form->field($model, 'preset')->dropDownList($model->getCustomOptions(), ['prompt' => '']) ?>
        <?php endif ?>




        <?php ActiveForm::end() ?>
    </div>

    <?php if (!empty($activePids)) : ?>
        <div class="well">
            <h4><?= Yii::t('dbManager', 'Active processes:') ?></h4>
            <?php foreach ($activePids as $pid => $cmd) : ?>
                <b><?= $pid ?></b>: <?= $cmd ?><br>
            <?php endforeach ?>
        </div>
    <?php endif ?>



    <?= GridView::widget([
        'dataProvider' => $dataProvider,
        'columns' => [
            //['class' => 'yii\grid\SerialColumn'],

            [
                'attribute' => 'type',
                'label' => Yii::t('dbManager', 'Type'),
            ],
            [
                'attribute' => 'name',
                'label' => Yii::t('dbManager', 'Name'),
            ],
            [
                'attribute' => 'size',
                'label' => Yii::t('dbManager', 'Size'),
            ],
            [
                'attribute' => 'create_at',
                'label' => Yii::t('dbManager', 'Create time'),
            ],

            [
                'class' => 'yii\grid\ActionColumn',
                'template' => '{download} {restore} {storage} {delete}',
                'buttons' => [
                    'download' => function ($url, $model) {
                        return Html::a(
                            '<span class="bx bxs-download"></span>',
                            [
                                'download',
                                'id' => $model['id'],
                            ],
                            [
                                'title' => Yii::t('dbManager', 'Download'),
                                'class' => 'btn btn-sm btn-primary',
                            ]
                        );
                    },
                    'restore' => function ($url, $model) {
                        return Html::a(
                            '<span class="bx bxs-save"></span>',
                            [
                                'restore',
                                'id' => $model['id'],
                            ],
                            [
                                'title' => Yii::t('dbManager', 'Restore'),
                                'class' => 'btn btn-sm btn-primary',
                            ]
                        );
                    },
                    'storage' => function ($url, $model) {
                        if (Yii::$app->has('backupStorage')) {
                            $exists = Yii::$app->backupStorage->has($model['name']);

                            return Html::a(
                                '<span class="bx bxs-hdd"></span>',
                                [
                                    'storage',
                                    'id' => $model['id'],
                                ],
                                [
                                    'title' => $exists ? Yii::t('dbManager', 'Delete from storage') : Yii::t('dbManager', 'Upload to storage'),
                                    'class' => $exists ? 'btn btn-sm btn-danger' : 'btn btn-sm btn-success',
                                ]
                            );
                        }
                    },
                    'delete' => function ($url, $model) {
                        return Html::a(
                            '<span class="bx bxs-trash"></span>',
                            [
                                'delete',
                                'id' => $model['id'],
                            ],
                            [
                                'title' => Yii::t('dbManager', 'Delete'),
                                'data-method' => 'post',
                                'data-confirm' => Yii::t('dbManager', 'Are you sure?'),
                                'class' => 'btn btn-sm btn-danger',
                            ]
                        );
                    },
                ],
            ],
        ],
    ]) ?>

</div>