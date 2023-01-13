# birfatura-whmcs

Birfatura.com kullanan WHMCS mağazaları için özel entegrasyon API.

- Sadece TOKEN sabit değerini kendinize özel bir HASH ile değiştirip, Birfatura.com üzerinden mağazanızı eklerken tanımlamanız yeterlidir. Başka herhangi bir konfigürasyona ihtiyacınız bulunmamaktadır. Aşağıda belirteceğim durumlar yapabileceğiniz özelleştirmeler ve satış faturası kesimi ile alakalı işleyişi belirtmektedir.
- Bu API basitçe hazırlanmış, bir arayüz ile özelleştirilmeyen ve Birfatura'nın sistemi gereğince sabit bir TOKEN'i olan bir API'dır. Kişisel projelerim için hazırladığım bir çalışma olup, katkıda bulunmak amacıyla public olarak sunulmuştur. Eğer bu API'yi kullanıyorsanız, kestiğiniz ve keseceğiniz faturalardan siz sorumlusunuz. Testlerinizi mutlaka yapın :)

### Özelleştirme

- api.php dosyasının üst kısmında kendinize göre düzenleyebileceğiniz başka sabitler de bulunmaktadır. Fatura açıklamalarına ön ek, ürün kodlarına ön ek gibi. Dilediğiniz gibi bu sabitlerin değerlerini de değiştirebilirsiniz.
- Elbette, sizin ödeme aldığınız ödeme aracı firmaları farklıysa, paymentMethod fonksiyonu içerisindeki değerleri; Birfatura.com üzerindeki satış fatura ekleme sayfasındaki ödeme yöntemi kısmındaki seçeneklerle eşleştirerek değiştirebilirsiniz.
- .htaccess dosyasına sadece Birfatura'nın IP adreslerinin erişmesi için kural ekleyebilirsiniz. Eğer nginx veya farklı bir yazılım kullanıyorsanız, konfigürasyonu kendinize göre uyarlayınız.

### Satış Faturası Kesimi ile Alakalı Notlar

- Faturaya toplam tutardan daha fazla ödeme eklenirse aradaki fark "Ön Ödeme" olarak değerlendirilir ve o aradaki fark ek kalem olarak Birfatura üzerinde satış faturasına dahil edilir.
- WHMCS içerisinde müşterilerin kendi kredi bakiyeleri ile ödeme yaptığı faturalar; kredi ile ödenen kısmı iskonto olarak değerlendirilir ve geriye kalan ödediği tutara fatura kesilir. 
- Eğer fatura tamamen kredi bakiyesi ile ödendiyse toplam 0 TL olduğu için bu fatura kesilmez, çünkü daha önce Ön Ödeme olarak faturası kesilmiş olacaktır.
- Eğer kredi bakiye ekleme faturası ödendiyse, ödenen tutar KDV dahil tutar olarak hesaplanır ve öyle fatura edilir. Örneğin; 100 TL'lik ödeme yapıldıysa, KDV hariç fiyatı 84.74 TL'dir ve KDV dahil 100 TL'lik satış faturası kesilir.

#### Ek Not

_Birfatura.com üzerindeki WHMCS entegrasyonu BETA aşamasındadır ve sadece yeni siparişleri algılamaktadır. Yenileme faturalarını algılamamaktadır. Hazırlamış olduğum bu API ay içerinde ödenen tüm faturaları düzgünce eksiksiz kesilmesini sağlar. Siz yine de özel entegrasyonu aktif edip, faturaları resmileştirmeden önce Postman ile API yanıtlarını kontrol ediniz._
