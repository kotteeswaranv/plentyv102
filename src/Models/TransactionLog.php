<?php
/**
 * NOTICE OF LICENSE
 *
 * This source file is subject to the GNU General Public License
 *
 * @author Novalnet <technic@novalnet.de>
 * @copyright Novalnet
 * @license GNU General Public License
 *
 * Script : TransactionLog.php
 *
 */

namespace Novalnet\Models;

use Plenty\Modules\Plugin\DataBase\Contracts\Model;

/**
 * Class TransactionLog
 *
 * @property int     $id
 * @property int     $orderNo
 * @property int     $amount
 * @property int     $callbackAmount
 * @property string  $referenceTid
 * @property string  $transactionDatetime
 * @property string  $tid
 * @property string  $paymentName
 */
class TransactionLog extends Model
{
    public $id;
    public $orderNo;
    public $amount;
    public $callbackAmount;
    public $referenceTid;
    public $transactionDatetime;
    public $tid;
    public $paymentName;

    /**
     * Returns table name to create during build
     *
     * @return string
     */
    public function getTableName(): string
    {
        return 'Novalnet::TransactionLog';
    }
}
