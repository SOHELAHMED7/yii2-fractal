<?php

/**
 * @copyright Copyright (c) 2020 Insolita <webmaster100500@ya.ru> and contributors
 * @license https://github.com/insolita/yii2-fractal/blob/master/LICENSE
 */

namespace insolita\fractal\actions;

use Closure;
use insolita\fractal\exceptions\ValidationException;
use insolita\fractal\RelationshipManager;
use League\Fractal\Resource\Item;
use Throwable;
use Yii;
use yii\base\Model;
use yii\db\ActiveRecord;
use yii\web\ServerErrorHttpException;

/**
 * Handler for routes PATCH /resource
 * With defined parentIdParam and parentIdAttribute Handler for  PATCH /resource/{parentId}/relation/{id}, modelClass
 * should be defined for related model for this case
 **/
class UpdateAction extends JsonApiAction
{
    use HasResourceTransformer;
    use HasParentAttributes;

    /**
     * @var array
     * Configuration for attaching relationships
     * Should contains key - relation name and array with
     *             idType - php type of resource ids for validation
     *             unlinkOnly = should unlinked relation models be removed
     *             validator = callback for custom id validation
     * Keep it empty for disable this ability
     * @see https://jsonapi.org/format/#crud-updating-resource-relationships
     * @example
     *  'allowedRelations' => [
     *       'author' => ['idType' => 'integer', 'unlinkOnly' =>true],
     *       'photos' => [
     *          'idType' => 'integer',
     *          'unlinkOnly' =>false,
     *          'validator' => function($model, array $ids) {
     *              $relatedModels = Relation::find()->where(['id' => $ids])->andWhere([additional conditions])->all();
     *              if(count($relatedModels) < $ids) {
     *                throw new HttpException(422, 'Invalid photos ids');
     *        }
     * }],
     * ]
     **/
    public $allowedRelations = [];
    /**
     * @var string the scenario to be assigned to the model before it is validated and updated.
     */
    public $scenario = Model::SCENARIO_DEFAULT;
    /**
     * @var callable|Closure Callback after save model with all relations
     * @example
     *   'afterSave' => function ($model, $originalModel) {
     *           $model->doSomething();
     * }
     */
    public $afterSave = null;
    /**
     * @throws \yii\base\InvalidConfigException
     */
    public function init():void
    {
        parent::init();
        $this->initResourceTransformer();
        $this->validateParentAttributes();
    }

    /**
     * @param int|string $id
     * @return \League\Fractal\Resource\Item
     * @throws \Throwable
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\base\NotSupportedException
     * @throws \yii\db\Exception
     * @throws \yii\web\ForbiddenHttpException
     * @throws \yii\web\HttpException
     * @throws \yii\web\NotFoundHttpException
     */
    public function run($id):Item
    {
        /* @var $model ActiveRecord */
        $model = $this->isParentRestrictionRequired() ? $this->findModelForParent($id) : $this->findModel($id);
        $model->scenario = $this->scenario;
        if ($this->checkAccess) {
            call_user_func($this->checkAccess, $this->id, $model);
        }
        $originalModel = $model;
        RelationshipManager::validateRelationships($model, $this->getResourceRelationships(), $this->allowedRelations);
        if (empty($this->getResourceAttributes()) && $this->hasResourceRelationships()) {
            $transact = $model::getDb()->beginTransaction();
            try {
                $this->linkRelationships($model);
                $transact->commit();
            } catch (Throwable $e) {
                $transact->rollBack();
                throw $e;
            }
            return new Item($model, new $this->transformer, $this->resourceKey);
        }

        $transact = $model::getDb()->beginTransaction();
        try {
            $model->load($this->getResourceAttributes(), '');
            if ($model->save() === false && !$model->hasErrors()) {
                throw new ServerErrorHttpException('Failed to update the object for unknown reason.');
            }
            if ($model->hasErrors()) {
                throw new ValidationException($model->getErrors());
            }
            if (!empty($this->allowedRelations) && $this->hasResourceRelationships()) {
                $this->linkRelationships($model);
            }
            $transact->commit();
        } catch (Throwable $e) {
            $transact->rollBack();
            throw $e;
        }
        $model->refresh();
        if ($this->afterSave !== null) {
            call_user_func($this->afterSave, $model, $originalModel);
        }
        return new Item($model, new $this->transformer, $this->resourceKey);
    }

    /**
     * @param \yii\db\ActiveRecord $model
     * @throws \Throwable
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\base\NotSupportedException
     * @throws \yii\db\Exception
     * @throws \yii\web\HttpException
     * @throws \yii\web\NotFoundHttpException
     */
    private function linkRelationships(ActiveRecord $model):void
    {
        $relationships = $this->getResourceRelationships();
        $relationNames = array_keys($relationships);
        foreach ($relationNames as $relationName) {
            $options = $this->allowedRelations[$relationName];
            $manager = new RelationshipManager(
                $model,
                $relationName,
                $relationships[$relationName]['data'],
                $options['idType']
            );

            if (isset($options['validator']) && \is_callable($options['validator'])) {
                $manager->setIdValidateCallback($options['validator']);
            }
            $manager->patch($options['unlinkOnly']);
        }
    }
}
