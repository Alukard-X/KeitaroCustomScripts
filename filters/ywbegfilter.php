<?php
namespace Filters;

use Core\Filter\AbstractFilter;
use Core\Locale\LocaleService;
use Traffic\Model\StreamFilter;
use Traffic\RawClick;

/*
Кастомный фильтр для Кейтаро для реализации работы Эпсилон-жадного алгоритма многоруких бандитов.
Скопировать файл фильтра в папку application\filters затем перелогиниться в трекер
Устанавливаете фильтр в потоке, в поле пишите метрику, по которой будет выбираться лучшая прокла:
lp_ctr, epc_confirmed, cr, crs

©2021 by Yellow Web
 */
class ywbegfilter extends AbstractFilter
{
    public function getModes()
    {
        return [
            StreamFilter::ACCEPT => LocaleService::t('filters.binary_options.' . StreamFilter::ACCEPT),
            StreamFilter::REJECT => LocaleService::t('filters.binary_options.' . StreamFilter::REJECT),
        ];
    }

    public function getTemplate()
    {
        return 'Метрика для выявления лучшей проклы: 
		<select class="form-control" ng-model="filter.payload.metric">
			<option value="lp_ctr">LP CTR</option>
			<option value="epc_confirmed">EPC</option>
			<option value="cr">CR</option>
			<option value="crs">CRs</option>
		</select>
		<br/>
        За сколько дней брать стату для подсчёта лучшей метрики: <input type="number" class="form-control" ng-model="filter.payload.days" placeholder="1"/>
		<br/>
        Процент рандома: <input type="number" class="form-control" ng-model="filter.payload.percent" placeholder="Процент рандома: 10"/>';
    }

    public function isPass(StreamFilter $filter, RawClick $rawClick)
    {
		$apiKey="<YOUR_API_KEY>";
		$apiAddress="http://<YOUR_TRACKER_DOMAIN>";
		$tz='Europe/Samara'; //здесь меняем пояс, если ваш часовой пояс не Москва!!!
		$explorationPercent=10; //сколько процентов трафа отправлять на рандомную проклу
		
		//дальше ничего не трогаем, если не умеем!
		$metric='lp_ctr';
		$days=1;
		date_default_timezone_set($tz);
		//взяли настройки из настроек фильтра
		$settings= $filter->getPayload();
		if (isset($settings['percent']))
			$explorationPercent=$settings['percent'];
		if (isset($settings['metric']))
			$metric=$settings['metric'];
		if (isset($settings['days']))
			$days=$settings['days'];
		//file_put_contents("/var/www/keitaro/application/filters/eg.txt",$explorationPercent.' '.$metric.' '.$days); //отладка

		$apiAddress=$apiAddress.'/admin_api/v1';
		$streamId=$filter->getStreamId();
		//получаем страну, чтобы потом построить отчёт только по нужной стране
		$country=$rawClick->getCountry();
		
		//запрашиваем все данные по потоку, чтобы вынуть из него идентификаторы лендингов
		$fullAddress=$apiAddress.'/streams/'.$streamId;
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 
		curl_setopt($ch, CURLOPT_URL, $fullAddress);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Api-Key: '.$apiKey));
		$res=curl_exec($ch);
		$streamParams=json_decode($res,true);

		//вынимаем идентификаторы лендов
		$landingIds=[];
		foreach($streamParams['landings'] as $landing)
		{
			array_push($landingIds,$landing['landing_id']);
		}
	
		$selectedLandId=-1;
		$random=rand(1,100);
		if ($random<=$explorationPercent){ //в $explorationPercent случаев выбираем рандомную проклу
			$random=rand(1,count($landingIds))-1;
			$selectedLandId=$landingIds[$random];
		}
		else{ //в остальных случаях выбираем лучшую по выбранному показателю (по умолчанию LP CTR)
			//запрашиваем отчёт по нашим проклам за нужное кол-во дней
			$days-=1;
			$from= date("Y-m-d", strtotime("-".$days." day"));
			$params = [
				'columns' => [],
				'metrics' => [$metric],
				'filters' => [
					['name' => 'landing_id', 'operator' => 'IN_LIST', 'expression' => $landingIds],
					['name' => 'country_code', 'operator'=> 'EQUALS', 'expression'=> $country]
				],
				'grouping' => ['landing'],
				'range' => [
					'timezone' => $tz,
					'from' => $from,
					'to' => date('Y-m-d')
				]
			];
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 
			curl_setopt($ch, CURLOPT_URL, $apiAddress.'/report/build');
			curl_setopt($ch, CURLOPT_HTTPHEADER, array('Api-Key: '.$apiKey));
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));		
			$res=curl_exec($ch);
			$report=json_decode($res,true);
			file_put_contents("/var/www/keitaro/application/filters/eg.txt",$res); //отладка
			
			//выбираем лучшую проклу по показателям
			$bestMetric=0;
			$bestLandId=0;
			foreach($report['rows'] as $row)
			{
				if ($row[$metric]>$bestMetric)
				{
					$bestMetric=$row[$metric];
					$bestLandId=$row['landing_id'];
				}
			}
			if ($bestLandId===0) {
				//ситуация, когда у нас все показатели равны 0, берём рандомную
				$random=rand(1,count($landingIds))-1;
				$bestLandId=$landingIds[$random];
			}
			$selectedLandId=$bestLandId;
		}
				
		//ставим в текущем потоке 100% трафа на выбранную проклу, и 0% для всех остальных
		$landObjects=[];
		foreach($landingIds as $l)
		{
			$share=($l==$selectedLandId?100:0);
			
			$landObj = (object) [
				'landing_id' => $l,
				'share' => $share,
				'state'=> 'active'
			];
			array_push($landObjects,$landObj);
		}
		
		if (count($landObjects)>0){
			$params = (object) ['landings' => $landObjects];
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 
			curl_setopt($ch, CURLOPT_URL, $apiAddress.'/streams/'.$streamId);
			curl_setopt($ch, CURLOPT_HTTPHEADER, array('Api-Key: '.$apiKey));
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));		
			$res=curl_exec($ch);
			$report=json_decode($res,true);
			curl_close($ch);
		}
				
		return ($filter->getMode() == StreamFilter::ACCEPT);
    }
}