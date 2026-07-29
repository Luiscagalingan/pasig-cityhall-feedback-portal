USE pasig_feedback_portal;

INSERT INTO feedback (office_id,visit_date,sex,age,client_type,service_received,timeliness_rating,client_handling_rating,quality_rating,overall_rating,comment,sentiment,sentiment_confidence,sentiment_source,average_rating,rating_percent,comment_score,final_score,source,submitted_at) VALUES
(1,CURDATE()-INTERVAL 42 DAY,'Female',31,'Pasigueño','Medical consultation',4,4,4,4,'Mabilis at maayos ang serbisyo. Very accommodating ang staff.','positive',0.92,'svm',4.00,100.00,100.00,100.00,'csv_import',NOW()-INTERVAL 42 DAY),
(1,CURDATE()-INTERVAL 35 DAY,'Male',46,'Pasigueño','Medical certificate',3,4,3,3,'Okay naman ang transaction pero medyo mahaba ang pila.','neutral',0.71,'svm',3.25,81.25,50.00,68.75,'csv_import',NOW()-INTERVAL 35 DAY),
(1,CURDATE()-INTERVAL 29 DAY,'Female',22,'Non-Pasigueño','Vaccination inquiry',2,2,2,2,'Sobrang tagal bago ako naasikaso at hindi malinaw ang instructions.','negative',0.95,'svm',2.00,50.00,0.00,30.00,'csv_import',NOW()-INTERVAL 29 DAY),
(1,CURDATE()-INTERVAL 21 DAY,'Male',38,'City Government Employee','Laboratory request',4,4,3,4,'Helpful ang staff and clear ang instructions.','positive',0.88,'svm',3.75,93.75,100.00,96.25,'csv_import',NOW()-INTERVAL 21 DAY),
(1,CURDATE()-INTERVAL 14 DAY,'Female',55,'Pasigueño','Medicine assistance',3,3,3,3,'Normal lang ang naging process at nakuha ko naman ang kailangan ko.','neutral',0.74,'svm',3.00,75.00,50.00,65.00,'csv_import',NOW()-INTERVAL 14 DAY),
(1,CURDATE()-INTERVAL 7 DAY,'Male',64,'Pasigueño','Senior consultation',2,3,2,2,'Mabagal ang queue at kulang ang upuan para sa senior citizens.','negative',0.91,'svm',2.25,56.25,0.00,33.75,'csv_import',NOW()-INTERVAL 7 DAY),
(1,CURDATE()-INTERVAL 2 DAY,'Female',27,'Pasigueño','Dental consultation',4,4,4,4,'Excellent service, malinis at mabait ang personnel.','positive',0.94,'svm',4.00,100.00,100.00,100.00,'csv_import',NOW()-INTERVAL 2 DAY);

INSERT INTO actions (feedback_id,office_id,title,details,status,created_at) VALUES
(3,1,'Review client feedback #3','Sobrang tagal bago ako naasikaso at hindi malinaw ang instructions.','in_progress',NOW()-INTERVAL 29 DAY),
(6,1,'Review client feedback #6','Mabagal ang queue at kulang ang upuan para sa senior citizens.','needs_action',NOW()-INTERVAL 7 DAY);
